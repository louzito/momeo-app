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
    use ProductTrait;

    /** @var list<string> */
    #[ORM\Column(name: 'bookable_resource_codes', type: Types::JSON)]
    private array $bookableResourceCodes = [];

    #[ORM\Column(name: 'bookable_resource_required')]
    private bool $bookableResourceRequired = false;

    /** @return list<string> */
    public function getBookableResourceCodes(): array { return $this->bookableResourceCodes; }
    /** @param list<string> $codes */
    public function setBookableResourceCodes(array $codes): void { $this->bookableResourceCodes = array_values(array_unique($codes)); }
    public function isBookableResourceRequired(): bool { return $this->bookableResourceRequired; }
    public function setBookableResourceRequired(bool $required): void { $this->bookableResourceRequired = $required; }

    protected function createTranslation(): ProductTranslationInterface
    {
        return new ProductTranslation();
    }
}
