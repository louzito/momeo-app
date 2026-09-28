<?php

declare(strict_types=1);

namespace App\Service\GiftCard;

use App\Entity\Booking;
use App\Entity\Channel\Channel;
use App\Entity\Order\Order;
use App\Entity\Payment\{GatewayConfig, Payment, PaymentMethod};
use App\Service\GiftVoucher\GiftOrderMarker;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\Registry;

/** Le crédit est un paiement distinct, jamais une remise sur le prix. */
final class GiftCardPaymentService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly GiftCardService $cards, private readonly Registry $workflows) {}

    public function quote(string $code): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        if (!$channel instanceof Channel) throw new \DomainException('Boutique indisponible.');
        $card = $this->cards->consult($code, (string) $channel->getCode(), (string) $channel->getBaseCurrency()?->getCode());
        if ($card->getStatus() !== 'active' || $card->getAvailable() <= 0) throw new \DomainException('Cette carte est expirée, inactive ou sans crédit disponible.');
        return ['available' => $card->getAvailable(), 'currency' => $card->getCurrency()];
    }

    /** Préparation avant le checkout Sylius : aucun crédit réservé à ce stade. */
    public function prepare(string $token, string $code): array
    {
        return $this->em->wrapInTransaction(function () use ($token, $code): array {
            $order = $this->order($token);
            if ($order->getCheckoutState() === 'completed' || $order->getTotal() <= 0 || $order->getGiftCardAmount() !== null || GiftOrderMarker::decode($order->getNotes()) !== null) throw new \DomainException('Cette commande ne peut pas utiliser de carte cadeau.');
            $card = $this->cards->consult($code, (string) $order->getChannel()?->getCode(), (string) $order->getCurrencyCode());
            if ($card->getStatus() !== 'active' || $card->getAvailable() <= 0) throw new \DomainException('Cette carte ne dispose pas de crédit utilisable.');
            $amount = min($order->getTotal(), $card->getAvailable());
            $payment = $order->getLastPayment();
            if (!$payment instanceof Payment || !in_array($payment->getState(), ['new', 'cart'], true)) throw new \DomainException('Le paiement a déjà commencé.');
            $method = $amount === $order->getTotal() ? $this->giftMethod($order) : $this->stripeMethod($order);
            $payment->setMethod($method);
            $payment->setDetails(['gift_card_prepared' => $card->getCode(), 'gift_card_prepared_amount' => $amount, 'gift_card_prepared_total' => $order->getTotal()]);
            $checkout = $this->workflows->get($order, 'sylius_order_checkout');
            if (!$checkout->can($order, 'select_payment')) throw new \DomainException('Renseignez vos coordonnées et le mode de remise avant le paiement.');
            $checkout->apply($order, 'select_payment');
            $this->em->flush();
            return ['amount' => $amount, 'remaining' => $order->getTotal() - $amount, 'paymentMethod' => $method->getCode()];
        });
    }

    /** Appelé après le checkout et la réservation métier. Les reprises sont idempotentes. */
    public function settle(string $token): array
    {
        return $this->em->wrapInTransaction(function () use ($token): array {
            $order = $this->order($token);
            if ($order->getCheckoutState() !== 'completed' || in_array($order->getState(), ['cancelled', 'fulfilled'], true)) throw new \DomainException('La commande ne peut pas être réglée.');
            foreach ($order->getPayments() as $payment) {
                if (isset($payment->getDetails()['gift_card_code'])) return $this->summary($order);
            }
            $payment = $order->getLastPayment();
            $code = $payment?->getDetails()['gift_card_prepared'] ?? null;
            if (!$payment instanceof Payment || !is_string($code) || !in_array($payment->getState(), ['new', 'processing'], true)) throw new \DomainException('Préparez votre carte cadeau avant le paiement.');
            $booking = $this->em->getRepository(Booking::class)->findOneBy(['orderNumber' => $order->getNumber()]);
            if ($order->getFulfillmentMode() === null && !$booking instanceof Booking) throw new \DomainException('Le créneau doit être réservé avant le paiement.');
            if ($booking instanceof Booking && !in_array($booking->getStatus(), [Booking::STATUS_CONFIRMED, Booking::STATUS_AWAITING_PAYMENT], true)) throw new \DomainException('La réservation n’est plus disponible.');
            $card = $this->cards->consult($code, (string) $order->getChannel()?->getCode(), (string) $order->getCurrencyCode());
            $this->em->refresh($card, LockMode::PESSIMISTIC_WRITE);
            $amount = (int) ($payment->getDetails()['gift_card_prepared_amount'] ?? 0);
            if (($payment->getDetails()['gift_card_prepared_total'] ?? null) !== $order->getTotal() || $amount > $card->getAvailable()) throw new \DomainException('Le montant ou le crédit disponible a changé. Recommencez votre commande.');
            if ($amount <= 0) throw new \DomainException('Le crédit disponible est insuffisant. Recommencez votre commande.');
            $remaining = $order->getTotal() - $amount;
            if ($remaining > 0) $method = $this->stripeMethod($order);
            // reserve() verrouille et relit commande puis carte ; aucun changement non flushé avant cet appel.
            $this->cards->reserve($code, $order->getId(), $amount);
            $gift = $remaining === 0 ? $payment : new Payment();
            if ($remaining > 0) {
                $payment->setMethod($method);
                $payment->setAmount($remaining);
                $payment->setDetails([]);
                $order->addPayment($gift);
                $this->em->persist($gift);
            }
            $gift->setMethod($this->giftMethod($order));
            $gift->setAmount($amount);
            $gift->setCurrencyCode($order->getCurrencyCode());
            $gift->setState('new');
            $gift->setDetails(['gift_card_code' => $code, 'gift_card_expires' => time() + 3600]);
            $this->em->flush();
            if ($remaining === 0) $this->completeGift($order, $gift);
            return $this->summary($order);
        });
    }

    /** Débit du crédit seulement quand le complément a été encaissé. */
    public function paid(Payment $payment): void
    {
        $order = $payment->getOrder();
        if (!$order instanceof Order || $payment->getMethod()?->getCode() === 'gift_card') return;
        foreach ($order->getPayments() as $gift) {
            if (!$gift instanceof Payment || !isset($gift->getDetails()['gift_card_code']) || $gift->getState() !== 'new') continue;
            $paid = 0;
            foreach ($order->getPayments() as $part) if ($part->getState() === 'completed') $paid += $part->getAmount();
            if ($paid + $gift->getAmount() === $order->getTotal()) $this->completeGift($order, $gift);
        }
    }

    public function failed(Order $order): void
    {
        $this->cards->releaseForOrder($order->getId());
        foreach ($order->getPayments() as $gift) {
            if (isset($gift->getDetails()['gift_card_code']) && $gift->getState() === 'new') $gift->setState('cancelled');
        }
    }

    /** Même échéance que la session Stripe ; un abandon sans session est aussi libéré. */
    public function expire(): int
    {
        $count = 0;
        $pending = $this->em->getRepository(Payment::class)->findBy(['state' => 'new']);
        foreach ($pending as $payment) {
            $details = $payment->getDetails();
            if (!isset($details['gift_card_code'], $details['gift_card_expires']) || $details['gift_card_expires'] > time() || ($details['gift_card_stripe_started'] ?? false)) continue;
            $this->em->wrapInTransaction(function () use ($payment, &$count): void {
                $order = $this->order((string) $payment->getOrder()?->getTokenValue());
                $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);
                if ($payment->getState() !== 'new' || ($payment->getDetails()['gift_card_stripe_started'] ?? false)) return;
                $this->failed($order);
                foreach ($order->getPayments() as $part) {
                    if (in_array($part->getState(), ['new', 'processing'], true)) {
                        $workflow = $this->workflows->get($part, 'sylius_payment');
                        if ($workflow->can($part, 'cancel')) $workflow->apply($part, 'cancel');
                    }
                }
                $booking = $this->em->getRepository(Booking::class)->findOneBy(['orderNumber' => $order->getNumber()]);
                if ($booking instanceof Booking && $booking->getPaymentState() !== 'paid') {
                    $booking->setStatus(Booking::STATUS_CANCELLED); $booking->setPaymentState('cancelled');
                }
                ++$count;
            });
        }
        return $count;
    }

    public static function breakdown(Order $order): array
    {
        $gift = $bank = $refunded = 0;
        foreach ($order->getPayments() as $payment) {
            if (isset($payment->getDetails()['gift_card_code']) && !in_array($payment->getState(), ['cancelled', 'failed'], true)) {
                $gift += $payment->getAmount();
                $refunded += $payment instanceof Payment ? $payment->getRefundedAmount() : 0;
            } elseif ($payment->getState() === 'completed') $bank += $payment->getAmount();
        }
        $later = 0;
        foreach ($order->getAdjustments('todatempo_payment_terms') as $adjustment) $later -= $adjustment->getAmount();
        return ['giftCard' => $gift, 'bankPaid' => $bank, 'giftCardRefunded' => $refunded, 'dueNow' => $order->getTotal(), 'dueLater' => $later];
    }

    public function summary(Order $order): array
    {
        $result = self::breakdown($order);
        $result += ['paymentId' => null, 'paymentMethod' => 'gift_card', 'remaining' => max(0, $order->getTotal() - $result['giftCard']), 'status' => $order->getPaymentState()];
        foreach ($order->getPayments() as $payment) {
            if ($payment->getMethod()?->getCode() === 'stripe_web_elements') { $result['paymentId'] = $payment->getId(); $result['paymentMethod'] = 'stripe_web_elements'; }
            if (isset($payment->getDetails()['gift_card_code'])) {
                $card = $this->cards->consult($payment->getDetails()['gift_card_code'], (string) $order->getChannel()?->getCode(), (string) $order->getCurrencyCode());
                $result['cardBalance'] = $card->getAvailable();
                if (in_array($payment->getState(), ['cancelled', 'failed'], true)) throw new \DomainException('Ce paiement a expiré. Recommencez votre commande.');
            }
        }
        return $result;
    }

    private function completeGift(Order $order, Payment $gift): void
    {
        $this->cards->debit($gift->getDetails()['gift_card_code'], $order->getId());
        $workflow = $this->workflows->get($gift, 'sylius_payment');
        if (!$workflow->can($gift, 'complete')) throw new \DomainException('Le paiement cadeau ne peut pas être confirmé.');
        $workflow->apply($gift, 'complete');
        $order->setPaymentState('paid');
        $booking = $this->em->getRepository(Booking::class)->findOneBy(['orderNumber' => $order->getNumber()]);
        if ($booking instanceof Booking) { $booking->setPaymentState('paid'); $booking->setStatus(Booking::STATUS_CONFIRMED); }
    }

    private function order(string $token): Order
    {
        if ($token === '') throw new \DomainException('Commande introuvable.');
        $order = $this->em->getRepository(Order::class)->findOneBy(['tokenValue' => $token]);
        if (!$order instanceof Order) throw new \DomainException('Commande introuvable.');
        $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
        foreach ($order->getPayments() as $payment) $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);
        return $order;
    }

    private function stripeMethod(Order $order): PaymentMethod
    {
        $method = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']);
        if (!$method instanceof PaymentMethod || !$method->isEnabled() || !$method->hasChannel($order->getChannel()) || trim((string) ($method->getGatewayConfig()?->getConfig()['secret_key'] ?? '')) === '') throw new \DomainException('Le complément par carte bancaire n’est pas disponible.');
        return $method;
    }

    private function giftMethod(Order $order): PaymentMethod
    {
        // Sérialise la création paresseuse du moyen interne, y compris entre commandes.
        $this->em->lock($order->getChannel(), LockMode::PESSIMISTIC_WRITE);
        $method = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => 'gift_card']);
        if (!$method instanceof PaymentMethod) {
            $gateway = new GatewayConfig(); $gateway->setGatewayName('gift_card'); $gateway->setFactoryName('offline'); $gateway->setConfig([]);
            $method = new PaymentMethod(); $method->setCode('gift_card'); $method->setGatewayConfig($gateway);
            $method->setCurrentLocale('fr_FR'); $method->setFallbackLocale('fr_FR'); $method->setName('Carte cadeau');
            $this->em->persist($gateway); $this->em->persist($method);
        }
        $method->setEnabled(true); $method->addChannel($order->getChannel());
        return $method;
    }
}
