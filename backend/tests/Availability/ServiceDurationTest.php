<?php

declare(strict_types=1);

namespace App\Tests\Availability;

use App\Entity\Product\Product;
use App\Service\Availability\ServiceDuration;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Product\Model\ProductAttributeValueInterface;

final class ServiceDurationTest extends TestCase
{
    public static function durations(): iterable
    {
        yield 'default' => [[], 60];
        yield 'legacy' => [[['momeo_duration', 90]], 90];
        yield 'modern wins' => [[['momeo_duration', 90], ['todatempo_duration', 45]], 45];
        yield 'modern first' => [[['todatempo_duration', 45], ['momeo_duration', 90]], 45];
        yield 'minimum' => [[['todatempo_duration', 1]], 15];
        yield 'maximum legacy' => [[['momeo_duration', 999]], 480];
    }

    #[DataProvider('durations')]
    public function testPublicAndAdminShareDuration(array $values, int $expected): void
    {
        $attributes = [];
        foreach ($values as [$code, $value]) {
            $attribute = $this->createStub(ProductAttributeValueInterface::class);
            $attribute->method('getCode')->willReturn($code);
            $attribute->method('getValue')->willReturn($value);
            $attributes[] = $attribute;
        }
        $product = $this->createStub(Product::class);
        $product->method('getAttributes')->willReturn(new ArrayCollection($attributes));
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($product);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $duration = new ServiceDuration($em);
        self::assertSame($expected, $duration->forProduct($product));
        self::assertSame($expected, $duration->forCode('service'));
    }

    public function testMissingProductKeepsDefault(): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        self::assertSame(60, (new ServiceDuration($em))->forCode('missing'));
    }
}
