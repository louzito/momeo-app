<?php

declare(strict_types=1);

namespace App\Entity\Order;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Order as BaseOrder;
use Sylius\MolliePlugin\Entity\AbandonedEmailOrderTrait;
use Sylius\MolliePlugin\Entity\MolliePaymentIdOrderTrait;
use Sylius\MolliePlugin\Entity\OrderInterface;
use Sylius\MolliePlugin\Entity\QRCodeOrderTrait;
use Sylius\MolliePlugin\Entity\RecurringOrderTrait;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_order')]
class Order extends BaseOrder implements OrderInterface
{
    #[ORM\Column(name: 'checkout_key', length: 64, unique: true, nullable: true)]
    private ?string $checkoutKey = null;
    #[ORM\Column(name: 'checkout_context', type: 'json', nullable: true)]
    private ?array $checkoutContext = null;
    public function getCheckoutKey(): ?string { return $this->checkoutKey; }
    public function getCheckoutContext(): ?array { return $this->checkoutContext; }
    public function configureCheckout(string $key, array $context): void
    {
        if ($this->checkoutKey !== null || !preg_match('/^[a-f0-9]{32,64}$/D', $key)) throw new \DomainException('Référence de panier invalide.');
        $this->checkoutKey = $key; $this->checkoutContext = $context;
    }
    public function updateCheckoutContext(array $context): void { $this->checkoutContext = $context; }
    public function getMixedGiftPurchases(): array { return $this->checkoutContext['gifts'] ?? []; }
    public function getGiftEligibleTotal(): int
    {
        if ($this->giftCardAmount !== null) return 0;
        return max(0, $this->getTotal() - array_sum(array_column($this->getMixedGiftPurchases(), 'amount')));
    }

    /** Marquage serveur d’une commande dédiée à l’achat d’une carte monétaire. */
    #[ORM\Column(name: 'gift_card_amount', type: 'integer', nullable: true)]
    private ?int $giftCardAmount = null;

    public function getGiftCardAmount(): ?int { return $this->giftCardAmount; }
    public function setGiftCardAmount(?int $amount): void
    {
        if ($amount !== null && ($amount <= 0 || $amount > 2147483647)) {
            throw new \InvalidArgumentException('Le montant de la carte est invalide.');
        }
        $this->giftCardAmount = $amount;
    }

    /** Instantané de la vente, indépendant du profil client et des réglages futurs. */
    #[ORM\Column(name: 'gift_card_purchase', type: 'json', nullable: true)]
    private ?array $giftCardPurchase = null;

    public function getGiftCardPurchase(): ?array { return $this->giftCardPurchase; }

    public function configureGiftCardPurchase(int $amount, array $purchase): void
    {
        if ($this->giftCardPurchase !== null || $this->getCheckoutState() === 'completed') {
            throw new \DomainException('Cette carte cadeau ne peut plus être modifiée.');
        }
        if ($amount < 1000 || $amount > 100000) {
            throw new \InvalidArgumentException('Choisissez un montant entre 10 et 1 000 €.');
        }
        foreach (['buyerName', 'buyerEmail', 'recipientName', 'recipientEmail', 'message', 'delivery'] as $field) {
            if (!isset($purchase[$field]) || !is_string($purchase[$field])) {
                throw new \InvalidArgumentException('Les coordonnées du cadeau sont invalides.');
            }
            $purchase[$field] = trim($purchase[$field]);
        }
        foreach (['buyerName', 'recipientName'] as $field) {
            if ($purchase[$field] === '' || mb_strlen($purchase[$field]) > 100) {
                throw new \InvalidArgumentException('Renseignez les noms de l’acheteur et du destinataire (100 caractères maximum).');
            }
        }
        if (!in_array($purchase['delivery'], ['buyer', 'recipient'], true)) {
            throw new \InvalidArgumentException('Choisissez à qui envoyer la carte cadeau.');
        }
        foreach (['buyerEmail', 'recipientEmail'] as $field) {
            if ($field === 'recipientEmail' && $purchase['delivery'] === 'buyer' && $purchase[$field] === '') continue;
            if (strlen($purchase[$field]) > 254 || !filter_var($purchase[$field], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Renseignez une adresse e-mail valide.');
            }
        }
        if (mb_strlen($purchase['message']) > 1000) throw new \InvalidArgumentException('Le message est limité à 1 000 caractères.');
        if (!is_int($purchase['validityMonths'] ?? null) || $purchase['validityMonths'] < 1) throw new \InvalidArgumentException('La durée de validité est invalide.');
        $this->setGiftCardAmount($amount);
        $this->giftCardPurchase = $purchase;
    }

    public const PREPARATION_PENDING = 'pending';
    public const PREPARATION_PREPARING = 'preparing';
    public const PREPARATION_READY = 'ready';
    public const PREPARATION_HANDED_OVER = 'handed_over';

    #[ORM\Column(name: 'fulfillment_mode', length: 20, nullable: true)]
    private ?string $fulfillmentMode = null;

    #[ORM\Column(name: 'preparation_state', length: 20, nullable: true)]
    private ?string $preparationState = null;

    public function getFulfillmentMode(): ?string { return $this->fulfillmentMode; }
    public function setFulfillmentMode(?string $mode): void { $this->fulfillmentMode = $mode; }
    public function getPreparationState(): ?string { return $this->preparationState; }
    public function setPreparationState(?string $state): void { $this->preparationState = $state; }
    use MolliePaymentIdOrderTrait;
    use QRCodeOrderTrait;
    use RecurringOrderTrait;
    use AbandonedEmailOrderTrait;
}
