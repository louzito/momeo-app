<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Entity\Booking;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/** Shared atomic boundary for changes to an existing booking. */
final class BookingMutation
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function assertOwner(Booking $booking, string $email): void
    {
        if (!\App\Service\Customer\CustomerAccountAccess::ownsBooking($booking, $email)) {
            throw new BookingNotOwned('Réservation introuvable.');
        }
    }

    public function run(Booking $booking, callable $change, ?string $customerEmail = null): void
    {
        if ($customerEmail !== null) $this->assertOwner($booking, $customerEmail);
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->lock($booking, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($booking);
            if ($customerEmail !== null) $this->assertOwner($booking, $customerEmail);
            $change();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            // Rollback alone leaves changed fields in Doctrine's identity map.
            if ($this->entityManager->isOpen()) {
                try {
                    $this->entityManager->refresh($booking);
                } catch (\Throwable) {
                    // Detach even if reloading fails.
                }
                // Discard any update scheduled before a failing flush.
                $this->entityManager->detach($booking);
            }
            throw $exception;
        }
    }
}
