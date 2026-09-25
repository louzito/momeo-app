<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Entity\Booking;
use App\Service\Email\BookingEmailDispatcher;
use App\Service\Waitlist\WaitlistNotifier;

final class BookingLifecycle
{
    public function __construct(
        private readonly BookingMutation $mutation,
        private readonly CustomerBookingChangePolicy $changePolicy,
        private readonly BookingEmailDispatcher $emailDispatcher,
        private readonly WaitlistNotifier $waitlistNotifier,
    ) {}

    public function postpone(Booking $booking, string $reason): void
    {
        $this->transition($booking, Booking::STATUS_POSTPONED, 'Seul un rendez-vous confirmé peut être reporté.', $reason);
    }

    public function complete(Booking $booking): void
    {
        $this->transition($booking, Booking::STATUS_COMPLETED, 'Seul un rendez-vous confirmé peut être marqué comme effectué.');
    }

    public function noShow(Booking $booking): void
    {
        $this->transition($booking, Booking::STATUS_NO_SHOW, 'Seul un rendez-vous confirmé peut être marqué absent.');
    }

    private function transition(Booking $booking, string $status, string $error, string $reason = ''): void
    {
        $this->mutation->run($booking, function () use ($booking, $status, $error, $reason): void {
            if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) throw new BookingMutationFailed($error);
            $booking->setStatus($status);
            if ($status === Booking::STATUS_POSTPONED) {
                $booking->setPostponedReason($reason !== '' ? $reason : 'Report demandé par l’établissement.');
            }
        });
    }

    public function cancelByAdmin(Booking $booking): void
    {
        $this->mutation->run($booking, function () use ($booking): void {
            if (!\in_array($booking->getStatus(), [Booking::STATUS_CONFIRMED, Booking::STATUS_POSTPONED], true)) {
                throw new BookingMutationFailed('Ce rendez-vous ne peut plus être annulé.');
            }
            $booking->setStatus(Booking::STATUS_CANCELLED);
        });
        $this->notifyCancellation($booking);
    }

    public function cancelByCustomer(Booking $booking, string $email): void
    {
        try {
            $this->mutation->run($booking, function () use ($booking): void {
                $this->changePolicy->assertAllowed($booking, 'cancel');
                $booking->recordChange([
                    'action' => 'cancelled', 'actor' => 'customer',
                    'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'previousStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
                    'previousEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
                ]);
                $booking->setStatus(Booking::STATUS_CANCELLED);
            }, $email);
        } catch (BookingNotOwned $exception) {
            throw $exception;
        } catch (\DomainException $exception) {
            throw new BookingMutationFailed($exception->getMessage(), 'change_deadline_passed');
        } catch (\Throwable) {
            throw new BookingMutationFailed('L’annulation n’a pas pu être enregistrée.');
        }
        $this->notifyCancellation($booking);
    }

    private function notifyCancellation(Booking $booking): void
    {
        $this->emailDispatcher->cancellation($booking);
        try {
            $this->waitlistNotifier->notify($booking->getServiceCode(), $booking->getSlotStart(), $booking->getSlotEnd());
        } catch (\Throwable) {
            // Cancellation has committed; notification can be retried by administration.
        }
    }
}
