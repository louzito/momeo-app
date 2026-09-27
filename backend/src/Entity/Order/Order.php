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
