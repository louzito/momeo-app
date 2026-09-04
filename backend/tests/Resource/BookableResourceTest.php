<?php

declare(strict_types=1);

namespace App\Tests\Resource;

use App\Entity\BookableResource;
use App\Entity\Product\Product;
use PHPUnit\Framework\TestCase;

final class BookableResourceTest extends TestCase
{
    public function testResourceAndServiceRequirementAreConfigured(): void
    {
        $resource = new BookableResource();
        $resource->setCode('cabin_1');
        $resource->setName('Cabine 1');
        $resource->setType('cabin');
        $resource->setCapacity(2);
        $resource->setCalendar(['monday' => [['start' => '09:00', 'end' => '18:00']]]);

        self::assertSame(2, $resource->getCapacity());
        self::assertSame('09:00', $resource->getCalendar()['monday'][0]['start']);

        $product = new Product();
        $product->setBookableResourceCodes(['cabin_1', 'cabin_1']);
        $product->setBookableResourceRequired(true);
        self::assertSame(['cabin_1'], $product->getBookableResourceCodes());
        self::assertTrue($product->isBookableResourceRequired());
    }
}
