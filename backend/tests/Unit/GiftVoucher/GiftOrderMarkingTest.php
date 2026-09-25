<?php

declare(strict_types=1);

namespace App\Tests\Unit\GiftVoucher;

use App\Controller\ShopGiftOrderMarkerController;
use App\Entity\Order\Order;
use App\Service\GiftVoucher\GiftOrderMarking;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;

final class GiftOrderMarkingTest extends TestCase
{
    public function testTokenLookupPrecedesValidationAndOnlyValidMarkerIsFlushed(): void
    {
        $order = new Order();
        $order->setNotes('initial');
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::exactly(4))->method('findCartByTokenValue')->willReturnCallback(static fn (string $token) => $token === 'cart-token' ? $order : null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $controller = new ShopGiftOrderMarkerController(new GiftOrderMarking($repository, $em));
        $request = static fn (string $body) => Request::create('/', 'PATCH', content: $body);
        $missing = $controller('unknown-token', $request('{'));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(['error' => 'Panier introuvable.'], json_decode($missing->getContent(), true));
        foreach (['{', '{"beneficiaryEmail":"invalid"}'] as $body) {
            $invalid = $controller('cart-token', $request($body));
            self::assertSame(422, $invalid->getStatusCode());
            self::assertSame(['error' => "L'email du bénéficiaire est invalide."], json_decode($invalid->getContent(), true));
            self::assertSame('initial', $order->getNotes());
        }
        $response = $controller('cart-token', $request('{"beneficiaryName":" Alice ","beneficiaryEmail":" alice@example.test ","personalMessage":" Bonjour / été "}'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], json_decode($response->getContent(), true));
        self::assertSame('SKYBOOK_GIFT{"beneficiaryName":"Alice","beneficiaryEmail":"alice@example.test","personalMessage":"Bonjour / été"}', $order->getNotes());
    }
}
