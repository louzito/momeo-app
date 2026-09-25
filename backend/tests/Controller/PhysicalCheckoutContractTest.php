<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\{AdminPhysicalCommerceApiController, ShopPhysicalOrderApiController};
use App\Entity\Order\Order;
use App\Entity\Product\Product;
use App\Service\Commerce\{PhysicalCheckoutService, PhysicalPreparationService, PhysicalProductManagementService, PhysicalProductReadService};
use App\Tests\Commerce\PhysicalCheckoutServiceTest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PhysicalCheckoutContractTest extends TestCase
{
    public function testConfigureHttpPayloadAndErrors(): void
    {
        $order = PhysicalCheckoutServiceTest::cart();
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(static fn (array $criteria) => $criteria['tokenValue'] === 'cart' ? $order : null);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(4))->method('beginTransaction');
        $connection->expects(self::exactly(2))->method('commit');
        $connection->expects(self::exactly(2))->method('rollBack');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Order::class)->willReturn($repository);
        $em->method('getConnection')->willReturn($connection);
        $flushCount = 0;
        $em->expects(self::exactly(3))->method('flush')->willReturnCallback(static function () use (&$flushCount): void {
            if (++$flushCount === 3) throw new \RuntimeException('database failure');
        });
        $controller = new ShopPhysicalOrderApiController(new PhysicalCheckoutService($em), new PhysicalProductReadService($em));
        foreach (['{', '[]', '{"mode":"invalid"}'] as $body) {
            $response = $controller->configure('cart', Request::create('/', 'PATCH', content: $body));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['error' => 'Choisissez le retrait au centre ou la livraison.'], json_decode($response->getContent(), true));
        }
        foreach (['delivery' => 750, 'pickup' => 0] as $mode => $fee) {
            $response = $controller->configure('cart', Request::create('/', 'PATCH', content: json_encode(['mode' => $mode])));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['mode' => $mode, 'deliveryFee' => $fee], json_decode($response->getContent(), true));
        }
        $response = $controller->configure('unknown', Request::create('/', 'PATCH', content: '{"mode":"pickup"}'));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'Ce panier est introuvable ou déjà finalisé.'], json_decode($response->getContent(), true));
        $response = $controller->configure('cart', Request::create('/', 'PATCH', content: '{"mode":"pickup"}'));
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'La remise de la commande n’a pas pu être configurée.'], json_decode($response->getContent(), true));
    }

    public function testAdminResponsesAndNotFoundPrecedence(): void
    {
        $order = new Order();
        $order->setPreparationState(Order::PREPARATION_PENDING);
        $product = new Product();
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(static fn (array $criteria) => match ($criteria) {
            ['code' => 'p'] => $product,
            ['tokenValue' => 't'] => $order,
            default => null,
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects(self::exactly(2))->method('flush');
        $controller = new AdminPhysicalCommerceApiController(new PhysicalProductManagementService($em), new PhysicalPreparationService($em));
        foreach ([
            ['product', 'missing', '{', 404, ['error' => 'Produit introuvable.']],
            ['product', 'p', '{"type":"bad"}', 422, ['error' => 'Type de produit invalide.']],
            ['product', 'p', '{"type":"physical"}', 422, ['error' => 'Activez au moins un mode de remise.']],
            ['product', 'p', '{"type":"physical","pickupEnabled":true}', 200, ['code' => 'p', 'type' => 'physical']],
            ['preparation', 'missing', '{', 404, ['error' => 'Commande physique introuvable.']],
            ['preparation', 't', '{', 422, ['error' => 'Statut de préparation invalide.']],
            ['preparation', 't', '{"state":"ready"}', 200, ['state' => 'ready']],
        ] as [$method, $key, $body, $status, $payload]) {
            $response = $controller->$method($key, Request::create('/', 'PATCH', content: $body));
            self::assertSame($status, $response->getStatusCode());
            self::assertSame($payload, json_decode($response->getContent(), true));
        }
    }

    public function testPublicCatalogKeepsProjectionAndSelection(): void
    {
        $product = new Product();
        $product->setCurrentLocale('fr_FR');
        $product->setFallbackLocale('fr_FR');
        $product->setCode('p');
        $product->setName('Article');
        $product->setPickupEnabled(true);
        $product->setDeliveryFee(350);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findBy')->with(['todatempoType' => Product::TYPE_PHYSICAL, 'enabled' => true])->willReturn([$product]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Product::class)->willReturn($repository);
        $controller = new ShopPhysicalOrderApiController(new PhysicalCheckoutService($em), new PhysicalProductReadService($em));
        self::assertSame(['member' => [[
            'code' => 'p', 'name' => 'Article', 'shortDescription' => null, 'description' => null,
            'pickupEnabled' => true, 'deliveryEnabled' => false, 'deliveryFee' => 350,
            'defaultVariantData' => ['price' => 0, 'onHand' => 0, 'onHold' => 0], 'images' => [],
        ]]], json_decode($controller->products()->getContent(), true));
    }
}
