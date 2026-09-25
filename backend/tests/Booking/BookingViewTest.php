<?php

declare(strict_types=1);

namespace App\Tests\Booking;

use App\Entity\Booking;
use App\Service\Booking\BookingView;
use PHPUnit\Framework\TestCase;

final class BookingViewTest extends TestCase
{
    public function testAudienceAllowlistsAndJsonValues(): void
    {
        $booking = $this->booking();
        $view = new BookingView();
        $policy = ['cancelHours' => 12, 'rescheduleHours' => 48];
        $public = $this->json($view->publicBooking($booking));
        $admin = $this->json($view->admin($booking));
        $client = $this->json($view->client($booking, $policy));
        $history = $this->json($view->adminHistory($booking));

        self::assertSame(explode(' ', 'id reference status source serviceCode serviceName planningCode resourceCode jumpTypeId jumpTypeName customerName jumperName staffMemberId staffName slotStart slotEnd options paymentState orderNumber amount totalAmount balanceDue currencyCode'), array_keys($public));
        self::assertSame(explode(' ', 'id publicId reference status source serviceCode serviceName planningCode resourceCode jumpTypeId jumpTypeName customerName jumperName customerEmail customerPhone customerNotes staffMemberId staffName slotStart slotEnd orderNumber voucherCode options amount totalAmount balanceDue currencyCode paymentState postponedReason createdAt updatedAt'), array_keys($admin));
        self::assertSame(explode(' ', 'id reference status source jumpTypeId jumpTypeName jumperName customerName staffName resourceCode slotStart slotEnd options paymentState orderNumber amount totalAmount balanceDue currencyCode postponedReason changeHistory changePolicy'), array_keys($client));
        self::assertSame(explode(' ', 'id reference status source serviceCode serviceName staffName slotStart slotEnd amount totalAmount balanceDue currencyCode paymentState'), array_keys($history));
        self::assertSame('opaque-token', $public['id']);
        self::assertSame('opaque-token', $client['id']);
        self::assertSame(73, $admin['id']);
        self::assertSame(73, $history['id']);
        self::assertSame('opaque-token', $admin['publicId']);
        self::assertSame('Ada Lovelace', $public['customerName']);
        self::assertSame($public['customerName'], $public['jumperName']);
        self::assertSame('massage', $public['jumpTypeId']);
        self::assertSame('Massage', $public['jumpTypeName']);
        self::assertSame(['label' => 'Option'], $public['options']);
        self::assertSame($policy, $client['changePolicy']);
        self::assertSame([['reason' => 'private-history']], $client['changeHistory']);
        self::assertSame('private-notes', $admin['customerNotes']);
        self::assertSame('private-voucher', $admin['voucherCode']);
        foreach ([$public, $client] as $restricted) {
            foreach (['customerEmail', 'customerPhone', 'customerNotes', 'voucherCode', 'smsReminderConsent'] as $key) {
                self::assertArrayNotHasKey($key, $restricted);
            }
        }
        foreach ([$public, $admin, $client, $history] as $data) {
            self::assertSame(1250, $data['amount']);
            self::assertSame(5000, $data['totalAmount']);
            self::assertSame(3750, $data['balanceDue']);
            self::assertSame('EUR', $data['currencyCode']);
            self::assertSame('confirmed', $data['status']);
            self::assertSame('partially_paid', $data['paymentState']);
            self::assertSame('2026-10-25T01:30:00+00:00', $data['slotStart']);
            self::assertSame('2026-10-25T02:30:00+00:00', $data['slotEnd']);
        }
        self::assertSame('private-notes', $booking->getCustomerNotes());
        self::assertCount(1, $booking->getChangeHistory());
    }

    public function testNullableAmountsAndRelationsAreNotCoerced(): void
    {
        $booking = $this->booking();
        $booking->setAmount(null);
        $booking->setTotalAmount(null);
        $booking->setBalanceDue(null);
        $booking->setPaymentState(null);
        $view = new BookingView();
        foreach ([$view->publicBooking($booking), $view->admin($booking), $view->client($booking, []), $view->adminHistory($booking)] as $data) {
            foreach (['amount', 'totalAmount', 'balanceDue', 'paymentState', 'staffName'] as $key) {
                self::assertNull($data[$key]);
            }
        }
    }

    private function booking(): Booking
    {
        $booking = new Booking();
        (new \ReflectionProperty(Booking::class, 'id'))->setValue($booking, 73);
        $booking->setPublicToken('opaque-token');
        $booking->setReference('B-73');
        $booking->setServiceCode('massage');
        $booking->setServiceName('Massage');
        $booking->setCustomerFirstName('Ada');
        $booking->setCustomerLastName('Lovelace');
        $booking->setCustomerEmail('private@example.test');
        $booking->setCustomerPhone('private-phone');
        $booking->setCustomerNotes('private-notes');
        $booking->setVoucherCode('private-voucher');
        $booking->setSmsReminderConsent(true);
        $booking->setPostponedReason('private-reason');
        $booking->recordChange(['reason' => 'private-history']);
        $booking->setSlotStart(new \DateTimeImmutable('2026-10-25T02:30:00+01:00'));
        $booking->setSlotEnd(new \DateTimeImmutable('2026-10-25T03:30:00+01:00'));
        $booking->setOptions(['label' => 'Option']);
        $booking->setAmount(1250);
        $booking->setTotalAmount(5000);
        $booking->setBalanceDue(3750);
        $booking->setPaymentState('partially_paid');
        return $booking;
    }

    private function json(array $data): array
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
