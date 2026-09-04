<?php

declare(strict_types=1);

namespace App\Reminder\MessageHandler;

use App\Availability\CenterTimeZoneProvider;
use App\Email\BookingEmailDispatcher;
use App\Entity\Booking;
use App\Entity\ReminderDelivery;
use App\Reminder\Message\SendBookingReminder;
use App\Reminder\Sms\SmsProvider;
use App\Reminder\Sms\SmsProviderDisabled;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendBookingReminderHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookingEmailDispatcher $emailDispatcher,
        private SmsProvider $smsProvider,
        private CenterTimeZoneProvider $timeZoneProvider,
    ) {
    }

    public function __invoke(SendBookingReminder $message): void
    {
        $delivery = $this->entityManager->find(ReminderDelivery::class, $message->deliveryId);
        if (!$delivery instanceof ReminderDelivery || $delivery->getStatus() === ReminderDelivery::STATUS_SENT || $delivery->getStatus() === ReminderDelivery::STATUS_SKIPPED) {
            return;
        }

        $booking = $delivery->getBooking();
        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            $delivery->markSkipped('Rendez-vous non confirmé ou annulé.');
            $this->entityManager->flush();
            return;
        }
        if ($delivery->getChannel() === 'sms' && (!$booking->hasSmsReminderConsent() || !$booking->getCustomerPhone())) {
            $delivery->markSkipped('Consentement SMS ou numéro de téléphone absent.');
            $this->entityManager->flush();
            return;
        }

        $delivery->markAttempt();
        try {
            if ($delivery->getChannel() === 'email') {
                $this->emailDispatcher->reminder($booking);
                $reference = null;
            } else {
                $localStart = $booking->getSlotStart()->setTimezone($this->timeZoneProvider->get());
                $reference = $this->smsProvider->send((string) $booking->getCustomerPhone(), sprintf(
                    'Rappel : votre rendez-vous %s est prévu le %s à %s.',
                    $booking->getReference(), $localStart->format('d/m/Y'), $localStart->format('H:i'),
                ));
            }
            $delivery->markSent($reference);
            $this->entityManager->flush();
        } catch (SmsProviderDisabled $exception) {
            $delivery->markSkipped($exception->getMessage());
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $delivery->markError($exception->getMessage());
            $this->entityManager->flush();
            throw $exception;
        }
    }
}
