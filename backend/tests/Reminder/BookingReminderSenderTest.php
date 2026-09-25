<?php

declare(strict_types=1);

namespace App\Tests\Reminder;

use App\Entity\Booking;
use App\Entity\ReminderDelivery;
use App\Reminder\Message\SendBookingReminder;
use App\Reminder\MessageHandler\SendBookingReminderHandler;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Email\BookingEmailDispatcher;
use App\Service\Reminder\BookingReminderSender;
use App\Service\Reminder\Sms\SmsProvider;
use App\Service\Reminder\Sms\SmsProviderDisabled;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use App\Service\Tenant\TenantUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Mailer\Sender\SenderInterface;

final class BookingReminderSenderTest extends TestCase
{
    public static function outcomes(): iterable
    {
        yield 'email sent and replayed' => ['email', 1];
        yield 'sent and replayed' => ['sent', 1];
        yield 'disabled provider' => ['disabled', 1];
        yield 'provider failure retried' => ['error', 2];
        yield 'cancelled booking' => ['cancelled', 0];
        yield 'no consent' => ['no_consent', 0];
    }

    #[DataProvider('outcomes')]
    public function testHistoricalMessageIsConsumedWithExistingDeliverySemantics(string $outcome, int $attempts): void
    {
        $booking = new Booking();
        $booking->setReference('RDV-42');
        $booking->setSlotStart(new \DateTimeImmutable('2027-06-01T10:00:00Z'));
        $booking->setStatus($outcome === 'cancelled' ? Booking::STATUS_CANCELLED : Booking::STATUS_CONFIRMED);
        $booking->setCustomerEmail('customer@example.test');
        $booking->setPublicToken('token');
        $booking->setCustomerPhone('+33600000000');
        $booking->setSmsReminderConsent($outcome !== 'no_consent');
        $delivery = new ReminderDelivery($booking, $outcome === 'email' ? 'email' : 'sms', 24);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('find')->with(ReminderDelivery::class, 42)->willReturn($delivery);
        $em->expects(self::exactly($outcome === 'error' ? 2 : 1))->method('flush');
        // Local repositories only; no database is opened.
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn(new \App\Entity\Channel\Channel());
        $em->method('getRepository')->willReturnCallback(static function (string $class) use ($repository): \Doctrine\ORM\EntityRepository {
            if ($class === \App\Entity\Channel\Channel::class) {
                return $repository;
            }
            throw new \RuntimeException('unit fixture');
        });
        $sms = $this->createMock(SmsProvider::class);
        $expectation = $sms->expects(self::exactly($outcome === 'email' ? 0 : $attempts))->method('send');
        if ($outcome === 'disabled') {
            $expectation->willThrowException(new SmsProviderDisabled('disabled'));
        } elseif ($outcome === 'error') {
            $expectation->willThrowException(new \RuntimeException('provider failed'));
        } elseif ($attempts > 0 && $outcome !== 'email') {
            $expectation->with('+33600000000', 'Rappel : votre rendez-vous RDV-42 est prévu le 01/06/2027 à 12:00.')->willReturn('provider-42');
        }
        $registry = new TenantRegistry('/nonexistent', false);
        $context = new TenantContext($registry, new TenantIdentifierResolver(), 'demo');
        $mail = $this->createMock(SenderInterface::class);
        if ($outcome === 'email') {
            $mail->expects(self::once())->method('send')->with('booking_reminder', ['customer@example.test'], self::callback(static fn (array $data): bool => $data['booking'] === $booking));
        } else {
            $mail->expects(self::never())->method('send');
        }
        $timezone = new CenterTimeZoneProvider($em);
        $emails = new BookingEmailDispatcher($mail, $em, $context, $timezone, new TenantUrlGenerator($registry, 'https://example.test'));
        $handler = new SendBookingReminderHandler(new BookingReminderSender($em, $emails, $sms, $timezone));
        // Literal payload from before #99: never derive its class name from the new service.
        $message = unserialize('O:40:"App\\Reminder\\Message\\SendBookingReminder":1:{s:10:"deliveryId";i:42;}');
        self::assertInstanceOf(SendBookingReminder::class, $message);
        $serializer = new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();
        $envelope = $serializer->decode($serializer->encode(new \Symfony\Component\Messenger\Envelope($message)));
        $message = $envelope->getMessage();
        self::assertInstanceOf(SendBookingReminder::class, $message);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $handler($message);
                self::assertNotSame('error', $outcome);
            } catch (\RuntimeException $exception) {
                self::assertSame('error', $outcome);
                self::assertSame('provider failed', $exception->getMessage());
            }
        }
        self::assertSame($attempts, $delivery->getAttempts());
        self::assertSame(match ($outcome) { 'sent', 'email' => ReminderDelivery::STATUS_SENT, 'error' => ReminderDelivery::STATUS_ERROR, default => ReminderDelivery::STATUS_SKIPPED }, $delivery->getStatus());
        self::assertSame($outcome === 'sent' ? 'provider-42' : null, $delivery->getProviderReference());
    }
}
