<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Controller\ShopCustomerGiftCardsController;
use App\Entity\Customer\Customer;
use App\Entity\GiftCard;
use App\Entity\GiftCardMovement;
use App\Entity\Order\Order;
use App\Entity\User\ShopUser;
use App\Service\Customer\CustomerGiftCards;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class CustomerGiftCardsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CustomerGiftCards $cards;
    private ShopUser $buyer;
    private ShopUser $recipient;
    private GiftCard $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->cards = self::getContainer()->get(CustomerGiftCards::class);
        $this->buyer = $this->user();
        $this->recipient = $this->user();
        $order = new Order();
        $order->setNumber('GIFT-'.bin2hex(random_bytes(8)));
        $order->setCustomer($this->buyer->getCustomer());
        $order->setCurrencyCode('EUR');
        $order->setLocaleCode('fr_FR');
        $order->setTokenValue(bin2hex(random_bytes(32)));
        $this->em->persist($order);
        $this->card = new GiftCard(self::getContainer()->get(TenantContext::class)->getSlug(), 'WEB', 'EUR', 10000, $order->getNumber(), new \DateTimeImmutable('+1 year'));
        $this->em->persist($this->card);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function user(): ShopUser
    {
        $customer = new Customer();
        $customer->setEmail(bin2hex(random_bytes(8)).'@example.test');
        $this->em->persist($customer);
        $user = new ShopUser();
        $user->setCustomer($customer);
        return $user;
    }

    public function testBuyerCannotSeeRecipientBalanceHistoryOrCodeAndRecipientMustClaim(): void
    {
        self::assertSame(['received' => [], 'purchased' => []], $this->cards->lists($this->recipient));
        $purchased = $this->cards->lists($this->buyer);
        self::assertSame([], $purchased['received']);
        self::assertCount(1, $purchased['purchased']);
        foreach (['code', 'available', 'reserved', 'history', 'beneficiary'] as $private) self::assertArrayNotHasKey($private, $purchased['purchased'][0]);
        $this->cards->claim($this->recipient, $this->card->getCode());
        $this->cards->claim($this->recipient, $this->card->getCode()); // Rejeu idempotent.
        $this->em->clear();
        $received = $this->cards->lists($this->recipient)['received'];
        self::assertCount(1, $received);
        self::assertSame(10000, $received[0]['available']);
        self::assertSame($this->card->getCode(), $received[0]['code']);
        self::assertSame([], $this->cards->lists($this->buyer)['received']);
        self::assertSame([], $this->cards->lists($this->recipient)['purchased']);
    }

    public function testAnotherAccountCannotTakeOverAnAttachedCard(): void
    {
        $this->cards->claim($this->recipient, $this->card->getCode());
        $this->expectException(\DomainException::class);
        $this->cards->claim($this->buyer, $this->card->getCode());
    }

    public function testEmailWithoutPossessionDoesNotAttachAnything(): void
    {
        $controller = self::getContainer()->get(ShopCustomerGiftCardsController::class);
        $response = $controller->claim(new Request(content: json_encode(['email' => $this->buyer->getEmail()])), $this->recipient);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->cards->lists($this->recipient)['received']);
    }

    public function testOtherEstablishmentCannotReadOrClaimTheCard(): void
    {
        self::getContainer()->get(TenantContext::class)->setSlug('another-establishment');
        self::assertSame(['received' => [], 'purchased' => []], $this->cards->lists($this->buyer));
        $this->expectException(\DomainException::class);
        $this->cards->claim($this->recipient, $this->card->getCode());
    }

    public function testUnknownCodeIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->cards->claim($this->recipient, str_repeat('0', 32));
    }

    public function testCurrentBalanceAndHistoryNeverExposeOtherOrders(): void
    {
        $this->cards->claim($this->recipient, $this->card->getCode());
        $this->card->reserve(7000);
        $this->card->debit(7000);
        $movement = new GiftCardMovement($this->card, hash('sha256', 'payment'), 'debit', 'PRIVATE-ORDER', 7000, 'PRIVATE-REFERENCE');
        $this->em->persist($movement);
        $this->em->flush();
        $this->em->clear();
        $view = $this->cards->lists($this->recipient)['received'][0];
        self::assertSame(3000, $view['available']);
        self::assertSame(0, $view['reserved']);
        self::assertSame('debit', $view['history'][0]['kind']);
        self::assertSame(7000, $view['history'][0]['amount']);
        self::assertStringNotContainsString('PRIVATE', json_encode($view));
        self::assertArrayNotHasKey('purchaseOrderNumber', $view);
    }

    public function testControllerUsesAuthenticatedIdentityAndDisablesCaching(): void
    {
        $controller = self::getContainer()->get(ShopCustomerGiftCardsController::class);
        $response = $controller->claim(new Request(content: json_encode(['code' => strtolower($this->card->getCode()), 'email' => $this->buyer->getEmail()])), $this->recipient);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame($this->recipient->getCustomer()->getId(), $this->card->getBeneficiary()->getId());
        $list = $controller->index($this->recipient);
        self::assertTrue($list->headers->hasCacheControlDirective('private'));
        self::assertTrue($list->headers->hasCacheControlDirective('no-store'));
        self::assertSame('ROLE_USER', (new \ReflectionClass($controller))->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
    }

    public function testAnonymousHttpAccessCannotReadOrAttachCards(): void
    {
        foreach (['GET' => '', 'POST' => '/claim'] as $method => $suffix) {
            $request = Request::create('/api/v2/shop/account/gift-cards'.$suffix, $method, server: ['HTTP_X_TODATEMPO_TENANT' => 'demo', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['code' => $this->card->getCode()]));
            $response = self::$kernel->handle($request);
            self::assertSame(401, $response->getStatusCode());
            self::assertStringNotContainsString($this->card->getCode(), $response->getContent());
        }
    }

    public function testNoCustomerHasNoCards(): void
    {
        self::assertSame(['received' => [], 'purchased' => []], $this->cards->lists(new ShopUser()));
    }
}
