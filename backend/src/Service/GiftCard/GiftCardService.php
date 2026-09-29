<?php

declare(strict_types=1);

namespace App\Service\GiftCard;

use App\Entity\GiftCard;
use App\Entity\GiftCardMovement;
use App\Entity\Order\Order;
use App\Service\GiftVoucher\GiftOrderMarker;
use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;

/** Toutes les écritures prennent les verrous commande puis carte, sur la connexion tenant. */
final class GiftCardService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TenantContext $tenant, private readonly SenderInterface $sender) {}

    public function issueFromPayment(PaymentInterface $payment): ?GiftCard
    {
        $order = $payment->getOrder();
        if ($order instanceof Order && $order->getCheckoutKey() !== null) return $this->issueMixed($payment, $order);
        if ($payment->getState() !== PaymentInterface::STATE_COMPLETED || !$order instanceof Order || $order->getGiftCardAmount() === null) return null;

        return $this->em->wrapInTransaction(function () use ($order): ?GiftCard {
            $this->em->lock($order, LockMode::PESSIMISTIC_WRITE);
            $number = $this->orderNumber($order);
            $existing = $this->em->getRepository(GiftCard::class)->findIssuedForOrder($number);
            if ($existing instanceof GiftCard) {
                $existing->assertShop($this->tenant->getSlug(), (string) $order->getChannel()?->getCode(), (string) $order->getCurrencyCode());
                if ($existing->getInitialAmount() !== $order->getGiftCardAmount()) throw new \DomainException('Cette émission existe avec un autre montant.');
                return $existing;
            }
            $amount = $order->getGiftCardAmount();
            if ($order->getCheckoutState() !== 'completed' || $amount !== $order->getTotal() || GiftOrderMarker::decode($order->getNotes()) !== null) {
                throw new \DomainException('La commande d’achat de carte est invalide.');
            }
            $paid = 0;
            foreach ($order->getPayments() as $candidate) {
                if ($candidate->getState() === PaymentInterface::STATE_COMPLETED && $candidate->getCurrencyCode() === $order->getCurrencyCode()) $paid += $candidate->getAmount();
            }
            if ($paid < $amount) return null;
            if ($paid !== $amount) throw new \DomainException('Le montant encaissé ne correspond pas au crédit de la carte.');
            // Une commande créditée ne peut jamais servir à émettre une autre carte.
            if ($this->em->getRepository(GiftCardMovement::class)->findForOrderForUpdate($number) !== []) {
                throw new \DomainException('Une carte cadeau ne peut pas financer une autre carte cadeau.');
            }
            $purchase = $order->getGiftCardPurchase();
            $months = $purchase['validityMonths'] ?? 12;
            $card = new GiftCard($this->tenant->getSlug(), (string) $order->getChannel()?->getCode(), (string) $order->getCurrencyCode(), $amount, $number, new \DateTimeImmutable(sprintf('+%d months', $months)));
            $this->em->persist($card);
            $this->record($card, 'issue', $number, $amount, $this->key('issue', $number));
            $email = $purchase === null ? $order->getCustomer()?->getEmail()
                : ($purchase['delivery'] === 'recipient' ? $purchase['recipientEmail'] : $purchase['buyerEmail']);
            if ($email) {
                // Le mailer existant met l’e-mail en file Doctrine dans cette transaction.
                // Le verrou commande et l’émission unique empêchent un second enfilement.
                $this->sender->send('gift_card', [$email], [
                    'card' => $card, 'channel' => $order->getChannel(), 'purchase' => $purchase,
                    'documentUrl' => isset($purchase['documentUrl']) ? $purchase['documentUrl'].'#'.$card->getCode() : null,
                    'shopUrl' => $purchase['shopUrl'] ?? null,
                ]);
            }

            return $card;
        });
    }

    /** One issue per gift line, after the entire mixed order has been paid. */
    private function issueMixed(PaymentInterface $payment, Order $order): ?GiftCard
    {
        if ($payment->getState() !== PaymentInterface::STATE_COMPLETED || $order->getMixedGiftPurchases() === []) return null;
        return $this->em->wrapInTransaction(function () use ($order): ?GiftCard {
            $this->em->lock($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getCheckoutState() !== 'completed' || $order->getState() === 'cancelled') throw new \DomainException('Commande invalide.');
            $paid = $bank = 0;
            foreach ($order->getPayments() as $part) {
                if ($part->getState() !== PaymentInterface::STATE_COMPLETED || $part->getCurrencyCode() !== $order->getCurrencyCode()) continue;
                $paid += $part->getAmount();
                if ($part->getMethod()?->getCode() !== 'gift_card') $bank += $part->getAmount();
            }
            $giftTotal = array_sum(array_column($order->getMixedGiftPurchases(), 'amount'));
            if ($paid < $order->getTotal()) return null;
            if ($paid !== $order->getTotal() || $bank < $giftTotal) throw new \DomainException('Les cartes offertes doivent être payées intégralement sans crédit cadeau.');
            $first = null; $number = $this->orderNumber($order);
            foreach ($order->getMixedGiftPurchases() as $index => $purchase) {
                $line = $index + 1;
                $card = $this->em->getRepository(GiftCard::class)->findIssuedForOrder($number, $line);
                if ($card !== null) { $first ??= $card; continue; }
                $card = new GiftCard($this->tenant->getSlug(), (string) $order->getChannel()?->getCode(), (string) $order->getCurrencyCode(), $purchase['amount'], $number, new \DateTimeImmutable(sprintf('+%d months', $purchase['validityMonths'])));
                $card->setPurchaseLine($line);
                $this->em->persist($card);
                $this->record($card, 'issue', $number, $purchase['amount'], $this->key('issue', $number, (string) $line));
                $this->sender->send('gift_card', [$purchase['delivery'] === 'recipient' ? $purchase['recipientEmail'] : $purchase['buyerEmail']], [
                    'card' => $card, 'channel' => $order->getChannel(), 'purchase' => $purchase,
                    'documentUrl' => $purchase['documentUrl'].'#'.$card->getCode(), 'shopUrl' => $purchase['shopUrl'],
                ]);
                $first ??= $card;
            }
            return $first;
        });
    }

    /** Consultation interne ; aucune recherche publique par code n’est exposée. */
    public function consult(string $code, string $channel, string $currency): GiftCard
    {
        $card = $this->findCard($code);
        $card->assertShop($this->tenant->getSlug(), $channel, $currency);
        return $card;
    }

    public function reserve(string $code, int $orderId, int $amount): GiftCardMovement
    {
        return $this->operate($code, $orderId, 'reserve', $amount);
    }

    public function debit(string $code, int $orderId): GiftCardMovement
    {
        return $this->operate($code, $orderId, 'debit');
    }

    public function release(string $code, int $orderId): GiftCardMovement
    {
        return $this->operate($code, $orderId, 'release');
    }

    /** Plusieurs restitutions partielles possibles, clé stable de remboursement obligatoire. */
    public function refund(string $code, int $orderId, int $amount, string $refundKey): GiftCardMovement
    {
        if (trim($refundKey) === '' || strlen($refundKey) > 255) throw new \InvalidArgumentException('La référence de restitution est invalide.');
        return $this->operate($code, $orderId, 'refund', $amount, $refundKey);
    }

    /** Appelé après un échec de paiement ; une réservation libérée est définitive pour cette commande. */
    public function releaseForOrder(int $orderId): void
    {
        $order = $this->em->find(Order::class, $orderId);
        // Un paiement échoué peut appartenir à un panier sans numéro : ne pas perturber ce parcours.
        if (!$order instanceof Order || !$order->getNumber()) return;
        $this->em->wrapInTransaction(function () use ($order, $orderId): void {
            $this->em->lock($order, LockMode::PESSIMISTIC_WRITE);
            $repository = $this->em->getRepository(GiftCardMovement::class);
            $movements = $repository->findForOrderForUpdate($order->getNumber());
            $reservations = array_filter($movements, static fn (GiftCardMovement $m): bool => $m->getKind() === 'reserve');
            foreach ($reservations as $reservation) {
                $debited = array_filter($movements, static fn (GiftCardMovement $m): bool => $m->getCard() === $reservation->getCard() && $m->getKind() === 'debit');
                if ($debited === []) $this->release($reservation->getCard()->getCode(), $orderId);
            }
        });
    }

    private function operate(string $code, int $orderId, string $kind, ?int $amount = null, string $refundKey = ''): GiftCardMovement
    {
        return $this->em->wrapInTransaction(function () use ($code, $orderId, $kind, $amount, $refundKey): GiftCardMovement {
            $order = $this->em->find(Order::class, $orderId, LockMode::PESSIMISTIC_WRITE);
            if (!$order instanceof Order) throw new \DomainException('Commande introuvable.');
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $number = $this->orderNumber($order);
            $card = $this->findCard($code);
            // refresh est indispensable : lock seul laisserait un solde périmé dans l’identity map.
            $this->em->refresh($card, LockMode::PESSIMISTIC_WRITE);
            $card->assertShop($this->tenant->getSlug(), (string) $order->getChannel()?->getCode(), (string) $order->getCurrencyCode());
            $key = $this->key($kind, $number, $refundKey);
            $repository = $this->em->getRepository(GiftCardMovement::class);
            $movements = $repository->findForOrderForUpdate($number, $card);
            $existing = null;
            foreach ($movements as $movement) if ($movement->getOperationKey() === $key) $existing = $movement;
            if ($existing instanceof GiftCardMovement) {
                if ($amount !== null && $existing->getAmount() !== $amount) throw new \DomainException('Cette opération existe avec un autre montant.');
                return $existing;
            }
            $reserved = $debited = $released = $refunded = 0;
            foreach ($movements as $movement) {
                match ($movement->getKind()) {
                    'reserve' => $reserved += $movement->getAmount(),
                    'debit' => $debited += $movement->getAmount(),
                    'release' => $released += $movement->getAmount(),
                    'refund' => $refunded += $movement->getAmount(),
                    default => null,
                };
            }
            if ($kind === 'reserve') {
                if ($order->getGiftCardAmount() !== null || GiftOrderMarker::decode($order->getNotes()) !== null) throw new \DomainException('Une carte cadeau ne peut pas financer une autre carte cadeau.');
                if (in_array($order->getState(), ['cancelled', 'fulfilled'], true) || in_array($order->getPaymentState(), ['paid', 'refunded'], true)) throw new \DomainException('Cette commande ne peut plus être réglée par carte cadeau.');
                // Le verrou commande sérialise aussi les réservations faites avec différentes cartes.
                $committed = 0;
                foreach ($repository->findForOrderForUpdate($number) as $movement) {
                    if ($movement->getKind() === 'reserve') {
                        if ($movement->getCard() !== $card) throw new \DomainException('Une seule carte cadeau est autorisée par commande.');
                        $committed += $movement->getAmount();
                    }
                    if ($movement->getKind() === 'release') $committed -= $movement->getAmount();
                }
                if ($amount === null || $amount > $order->getGiftEligibleTotal() - $committed) throw new \DomainException('Le montant dépasse le total de la commande.');
                $card->reserve($amount);
            } elseif ($kind === 'refund') {
                if ($amount === null || $amount > $debited - $refunded) throw new \DomainException('Le montant dépasse le débit de cette commande.');
                $card->refund($amount);
            } else {
                if ($reserved === 0 || $debited !== 0 || $released !== 0) throw new \DomainException('Aucune réservation disponible pour cette commande.');
                $amount = $reserved;
                if ($kind === 'debit') $card->debit($amount);
                else $card->release($amount);
            }
            return $this->record($card, $kind, $number, $amount, $key, $refundKey === '' ? null : $refundKey);
        });
    }

    private function findCard(string $code): GiftCard
    {
        $card = $this->em->getRepository(GiftCard::class)->findOneBy(['code' => strtoupper(trim($code)), 'establishment' => $this->tenant->getSlug()]);
        if (!$card instanceof GiftCard) throw new \DomainException('Carte cadeau introuvable.');
        return $card;
    }

    private function orderNumber(Order $order): string
    {
        $number = $order->getNumber();
        if ($number === null || $number === '') throw new \DomainException('La commande doit avoir une référence.');
        return $number;
    }

    private function key(string $kind, string $number, string $reference = ''): string
    {
        return hash('sha256', json_encode([$kind, $number, $reference], JSON_THROW_ON_ERROR));
    }

    private function record(GiftCard $card, string $kind, string $number, int $amount, string $key, ?string $reference = null): GiftCardMovement
    {
        $movement = new GiftCardMovement($card, $key, $kind, $number, $amount, $reference);
        $this->em->persist($movement);
        return $movement;
    }
}
