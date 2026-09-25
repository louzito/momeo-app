<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ShopCustomerAccountApiController;
use App\Entity\Customer\Customer;
use App\Entity\User\ShopUser;
use App\Tests\Customer\CustomerReadFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ShopCustomerAccountSecurityContractTest extends KernelTestCase
{
    use CustomerReadFixture;

    private function user(string $email): ShopUser
    {
        $customer = new Customer();
        $customer->setEmail($email);
        $user = new ShopUser();
        $user->setCustomer($customer);
        return $user;
    }

    public function testBookingListAndDetailAreScopedToAuthenticatedEmail(): void
    {
        $past = $this->bookingFixture(strtoupper($this->email), '-2 days');
        $future = $this->bookingFixture($this->email, '+2 days');
        $this->bookingFixture('other-'.$this->email, '+4 days');
        $this->bookingFixture(' '.$this->email.' ', '+5 days');
        $controller = self::getContainer()->get(ShopCustomerAccountApiController::class);
        $user = $this->user($this->email);
        $list = json_decode($controller->bookings($user)->getContent(), true);
        self::assertSame([$future->getPublicToken(), $past->getPublicToken()], array_column($list['member'], 'id'));
        $detail = json_decode($controller->booking($future->getPublicToken(), $user)->getContent(), true);
        self::assertSame($list['member'][0], $detail);
        self::assertSame(['id', 'reference', 'status', 'source', 'jumpTypeId', 'jumpTypeName', 'jumperName', 'customerName', 'staffName', 'resourceCode', 'slotStart', 'slotEnd', 'options', 'paymentState', 'orderNumber', 'amount', 'totalAmount', 'balanceDue', 'currencyCode', 'postponedReason', 'changeHistory', 'changePolicy'], array_keys($detail));
        self::assertSame(['member' => []], json_decode($controller->bookings($this->user('empty-'.$this->email))->getContent(), true));
    }

    public function testForeignAndMissingTokensAreRejectedBeforeReadsOrMutations(): void
    {
        $booking = $this->bookingFixture($this->email, '+3 days');
        $controller = self::getContainer()->get(ShopCustomerAccountApiController::class);
        foreach ([$booking->getPublicToken(), str_repeat('0', 32)] as $token) {
            foreach (['booking', 'cancel', 'reschedule'] as $action) {
                try {
                    $user = $this->user('other-'.$this->email);
                    $action === 'reschedule' ? $controller->reschedule($token, new Request(content: '{}'), $user) : $controller->$action($token, $user);
                    self::fail('A foreign or missing token must not be visible.');
                } catch (NotFoundHttpException $exception) {
                    self::assertSame('Réservation introuvable.', $exception->getMessage());
                }
            }
        }
        self::assertSame('confirmed', $booking->getStatus());
        self::assertSame([], $booking->getChangeHistory());
    }

    public function testAdminContactEmailChangeDoesNotTransferAccountOwnership(): void
    {
        $booking = $this->bookingFixture($this->email, '+3 days');
        self::getContainer()->get(\App\Service\Customer\ClientProfileService::class)->update($booking, ['email' => 'changed-'.$this->email, 'internalNotes' => 'Private'], 'admin');
        $controller = self::getContainer()->get(ShopCustomerAccountApiController::class);
        $original = json_decode($controller->booking($booking->getPublicToken(), $this->user($this->email))->getContent(), true);
        self::assertArrayNotHasKey('internalNotes', $original);
        self::assertSame(['member' => []], json_decode($controller->bookings($this->user('changed-'.$this->email))->getContent(), true));
        $this->expectException(NotFoundHttpException::class);
        $controller->booking($booking->getPublicToken(), $this->user('changed-'.$this->email));
    }

    public function testLegacyDetailAlsoRefusesOtherAccounts(): void
    {
        $booking = $this->bookingFixture($this->email, '+3 days');
        $response = self::getContainer()->get(\App\Controller\ShopBookingApiController::class)->show($booking->getPublicToken(), $this->user('other-'.$this->email));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testMissingInvoiceIsAdaptedToCustomer404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Facture introuvable.');
        self::getContainer()->get(ShopCustomerAccountApiController::class)->invoice('missing-'.bin2hex(random_bytes(16)), $this->user($this->email));
    }

    public function testOnlyPaidInvoicesOfTheSameEmailAreOwned(): void
    {
        $access = self::getContainer()->get(\App\Service\Invoice\InvoiceAccess::class);
        foreach ([[$this->email, 'paid', true], [strtoupper($this->email), 'paid', true], ['other-'.$this->email, 'paid', false], [$this->email, 'awaiting_payment', false], [null, 'paid', false]] as [$email, $state, $expected]) {
            $invoice = $this->createMock(\Sylius\InvoicingPlugin\Entity\InvoiceInterface::class);
            $order = $this->createMock(\Sylius\Component\Core\Model\OrderInterface::class);
            $order->method('getCustomer')->willReturn($email === null ? null : $this->user($email)->getCustomer());
            $invoice->method('order')->willReturn($order);
            $invoice->method('paymentState')->willReturn($state);
            self::assertSame($expected, $access->ownsInvoice($invoice, $this->user($this->email)));
        }
    }

    public function testOrderProjectionUsesCustomerIdentityAndDescendingCreationOrder(): void
    {
        $user = $this->user($this->email);
        $order = new \App\Entity\Order\Order();
        $order->setTokenValue('token');
        $order->setNumber('ORDER-'.bin2hex(random_bytes(6)));
        $order->setCurrencyCode('EUR');
        $order->setCreatedAt(new \DateTime('2026-01-01T12:00:00+00:00'));
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects(self::exactly(2))->method('findBy')->with(['customer' => $user->getCustomer()], ['createdAt' => 'DESC'])->willReturn([$order]);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('getRepository')->with(\App\Entity\Order\Order::class)->willReturn($repository);
        $reads = new \App\Service\Customer\CustomerAccountReadService(
            self::getContainer()->get(\App\Repository\BookingRepository::class),
            self::getContainer()->get(\App\Repository\GiftVoucherRepository::class),
            $em,
            self::getContainer()->get(\App\Service\Booking\CustomerBookingChangePolicy::class),
        );
        self::assertEquals(['member' => [[
            'id' => 'token', 'number' => $order->getNumber(), 'status' => $order->getState(),
            'paymentState' => $order->getPaymentState(), 'total' => 0, 'currency' => 'EUR',
            'createdAt' => '2026-01-01T12:00:00+00:00', 'kind' => 'direct',
        ]]], $reads->orders($user));
        $voucher = new \App\Entity\GiftVoucher();
        $voucher->setCode((string) random_int(1000000000, 9999999999));
        $voucher->setServiceCode('test');
        $voucher->setServiceName('Massage');
        $voucher->setAmount(1200);
        $voucher->setCurrencyCode('EUR');
        $voucher->setPurchaserName('Alice');
        $voucher->setPurchaserEmail($this->email);
        $voucher->setBeneficiaryEmail($this->email);
        $voucher->setPurchaseOrderNumber($order->getNumber());
        $voucher->setExpiresAt(new \DateTimeImmutable('+1 year'));
        $this->em->persist($voucher);
        $this->em->flush();
        self::assertSame('gift', $reads->orders($user)['member'][0]['kind']);
    }

    public function testAbsentCustomerProfileAndOrdersStayEmpty(): void
    {
        $controller = self::getContainer()->get(ShopCustomerAccountApiController::class);
        $user = new ShopUser();
        self::assertSame(['id' => null, 'email' => $user->getEmail(), 'firstName' => '', 'lastName' => '', 'phone' => ''], json_decode($controller->profile($user)->getContent(), true));
        self::assertSame(['member' => []], json_decode($controller->orders($user)->getContent(), true));
        $attribute = (new \ReflectionClass($controller))->getAttributes(IsGranted::class)[0]->newInstance();
        self::assertSame('ROLE_USER', $attribute->attribute);
    }
}
