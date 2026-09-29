<?php

declare(strict_types=1);

namespace App\Service\GiftCard;

use App\Entity\Channel\Channel;
use App\Entity\Customer\Customer;
use App\Entity\GiftCard;
use App\Entity\Order\Adjustment;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use App\Service\GiftVoucher\GiftVoucherConfig;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;

/** Commande monétaire dédiée : ni article de catalogue, ni stock, ni réservation. */
final class GiftCardCheckoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GiftVoucherConfig $config,
        private readonly TenantContext $tenant,
        private readonly TenantUrlGenerator $urls,
    ) {}

    public function offer(): array
    {
        $channel = $this->channel();
        return [
            'enabled' => $this->config->salesEnabled(), 'shopName' => $channel->getName(),
            'currency' => 'EUR', 'minimum' => 1000, 'maximum' => 100000,
            'presets' => [5000, 10000], 'validityMonths' => $this->config->validityMonths(),
            'paymentMethods' => array_map(static fn (PaymentMethod $method): array => [
                'code' => $method->getCode(), 'label' => $method->getCode() === 'bank_transfer' ? 'Virement bancaire' : 'Carte bancaire',
            ], $this->methods($channel)),
        ];
    }

    public function purchase(int $amount, array $data, string $methodCode): Order
    {
        if (!$this->config->salesEnabled()) throw new \DomainException('La vente de cartes cadeaux est désactivée dans cet établissement.');
        $channel = $this->channel();
        $method = null;
        foreach ($this->methods($channel) as $candidate) if ($candidate->getCode() === $methodCode) $method = $candidate;
        if ($method === null) throw new \DomainException('Ce moyen de paiement n’est pas disponible.');

        $order = new Order();
        // Seuls ces champs sont acceptés du client ; URLs et validité viennent du serveur.
        $purchase = array_intersect_key($data, array_flip(['buyerName', 'buyerEmail', 'recipientName', 'recipientEmail', 'message', 'delivery']));
        $purchase += ['recipientEmail' => '', 'message' => ''];
        $purchase['validityMonths'] = $this->config->validityMonths();
        $purchase['shopUrl'] = $this->urls->url($this->tenant->getSlug(), 'shop');
        $purchase['documentUrl'] = $this->urls->url($this->tenant->getSlug(), 'gift-card/print');
        $order->configureGiftCardPurchase($amount, $purchase);
        $purchase = $order->getGiftCardPurchase();

        return $this->em->wrapInTransaction(function () use ($order, $purchase, $channel, $method, $amount): Order {
            $email = mb_strtolower($purchase['buyerEmail']);
            $customer = $this->em->getRepository(Customer::class)->findOneBy(['emailCanonical' => $email]);
            if (!$customer instanceof Customer) {
                $customer = new Customer();
                $customer->setEmail($email);
                $customer->setEmailCanonical($email);
                $this->em->persist($customer);
            }
            // Ne jamais modifier un compte existant depuis un achat anonyme.
            $order->setCustomer($customer);
            $order->setChannel($channel);
            $order->setCurrencyCode('EUR');
            $order->setLocaleCode($channel->getDefaultLocale()?->getCode() ?? 'fr_FR');
            $order->setTokenValue(bin2hex(random_bytes(32)));
            $order->setNumber('GC-'.strtoupper(bin2hex(random_bytes(12))));
            $adjustment = new Adjustment();
            $adjustment->setType('todatempo_gift_card_purchase');
            $adjustment->setLabel('Carte cadeau');
            $adjustment->setAmount($amount);
            $order->addAdjustment($adjustment);
            $adjustment->lock();
            $order->completeCheckout();
            $order->setCheckoutState('completed');
            $order->setPaymentState('awaiting_payment');
            $payment = new Payment();
            $payment->setMethod($method);
            $payment->setCurrencyCode('EUR');
            $payment->setAmount($amount);
            $payment->setState('new');
            $order->addPayment($payment);
            $this->em->persist($order);
            $this->em->persist($payment);
            return $order;
        });
    }

    public function mixedPurchase(int $amount, array $data): array
    {
        if (!$this->config->salesEnabled()) throw new \DomainException('La vente de cartes cadeaux est désactivée.');
        $purchase = array_intersect_key($data, array_flip(['buyerName', 'buyerEmail', 'recipientName', 'recipientEmail', 'message', 'delivery']));
        $purchase += ['recipientEmail' => '', 'message' => ''];
        $purchase['validityMonths'] = $this->config->validityMonths();
        $purchase['shopUrl'] = $this->urls->url($this->tenant->getSlug(), 'shop');
        $purchase['documentUrl'] = $this->urls->url($this->tenant->getSlug(), 'gift-card/print');
        $validation = new Order(); $validation->configureGiftCardPurchase($amount, $purchase);
        return $validation->getGiftCardPurchase() + ['amount' => $amount];
    }

    /** Le code aléatoire de 128 bits est la preuve de possession, jamais un ID séquentiel. */
    public function document(string $code): ?array
    {
        $card = $this->em->getRepository(GiftCard::class)->findOneBy(['code' => $code, 'establishment' => $this->tenant->getSlug()]);
        if (!$card instanceof GiftCard) return null;
        $order = $this->em->getRepository(Order::class)->findOneBy(['number' => $card->getPurchaseOrderNumber()]);
        if (!$order instanceof Order) return null;
        $card->assertShop($this->tenant->getSlug(), (string) $order->getChannel()?->getCode(), (string) $order->getCurrencyCode());
        $purchase = $card->getPurchaseLine() > 0 ? ($order->getMixedGiftPurchases()[$card->getPurchaseLine() - 1] ?? null) : $order->getGiftCardPurchase();
        return [
            'code' => $card->getCode(), 'amount' => $card->getInitialAmount(), 'currency' => $card->getCurrency(),
            'expiresAt' => $card->getExpiresAt()->format('Y-m-d'), 'shopName' => $order->getChannel()?->getName(),
            'shopUrl' => $this->urls->url($this->tenant->getSlug(), 'shop'),
            'recipientName' => $purchase['recipientName'] ?? '', 'buyerName' => $purchase['buyerName'] ?? '',
            'message' => $purchase['message'] ?? '', 'status' => $card->getStatus(),
        ];
    }

    private function channel(): Channel
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        if (!$channel instanceof Channel || !$channel->isEnabled() || $channel->getBaseCurrency()?->getCode() !== 'EUR') {
            throw new \DomainException('La vente de cartes cadeaux n’est pas disponible dans cette boutique.');
        }
        return $channel;
    }

    private function methods(Channel $channel): array
    {
        return array_values(array_filter($this->em->getRepository(PaymentMethod::class)->findBy(['enabled' => true]),
            static fn (PaymentMethod $method): bool => $method->hasChannel($channel)
                && ($method->getCode() === 'bank_transfer' || ($method->getCode() === 'stripe_web_elements'
                    && trim((string) ($method->getGatewayConfig()?->getConfig()['secret_key'] ?? '')) !== ''))));
    }
}
