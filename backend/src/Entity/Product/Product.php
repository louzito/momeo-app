<?php

declare(strict_types=1);

namespace App\Entity\Product;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Sylius\Component\Core\Model\Product as BaseProduct;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\MolliePlugin\Entity\ProductInterface;
use Sylius\MolliePlugin\Entity\ProductTrait;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_product')]
class Product extends BaseProduct implements ProductInterface
{
    public const TYPE_SERVICE = 'service';
    public const TYPE_OPTION = 'option';
    public const TYPE_PHYSICAL = 'physical';

    use ProductTrait;

    /** @var list<string> */
    #[ORM\Column(name: 'bookable_resource_codes', type: Types::JSON)]
    private array $bookableResourceCodes = [];

    #[ORM\Column(name: 'bookable_resource_required')]
    private bool $bookableResourceRequired = false;

    #[ORM\Column(name: 'todatempo_type', length: 20, options: ['default' => self::TYPE_SERVICE])]
    private string $todatempoType = self::TYPE_SERVICE;

    #[ORM\Column(name: 'pickup_enabled', options: ['default' => false])]
    private bool $pickupEnabled = false;

    #[ORM\Column(name: 'delivery_enabled', options: ['default' => false])]
    private bool $deliveryEnabled = false;

    #[ORM\Column(name: 'delivery_fee', options: ['default' => 0])]
    private int $deliveryFee = 0;

    /** @return list<string> */
    public function getBookableResourceCodes(): array { return $this->bookableResourceCodes; }
    /** @param list<string> $codes */
    public function setBookableResourceCodes(array $codes): void { $this->bookableResourceCodes = array_values(array_unique($codes)); }
    public function isBookableResourceRequired(): bool { return $this->bookableResourceRequired; }
    public function setBookableResourceRequired(bool $required): void { $this->bookableResourceRequired = $required; }
    public function getTodatempoType(): string { return $this->todatempoType; }
    public function setTodatempoType(string $type): void
    {
        if (!\in_array($type, [self::TYPE_SERVICE, self::TYPE_OPTION, self::TYPE_PHYSICAL], true)) {
            throw new \InvalidArgumentException('Type de produit invalide.');
        }
        $this->todatempoType = $type;
    }
    public function isPhysical(): bool { return $this->todatempoType === self::TYPE_PHYSICAL; }
    public function isPickupEnabled(): bool { return $this->pickupEnabled; }
    public function setPickupEnabled(bool $enabled): void { $this->pickupEnabled = $enabled; }
    public function isDeliveryEnabled(): bool { return $this->deliveryEnabled; }
    public function setDeliveryEnabled(bool $enabled): void { $this->deliveryEnabled = $enabled; }
    public function getDeliveryFee(): int { return $this->deliveryFee; }
    public function setDeliveryFee(int $fee): void { $this->deliveryFee = max(0, $fee); }

    protected function createTranslation(): ProductTranslationInterface
    {
        return new ProductTranslation();
    }
}
