<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Booking;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/** Serialises every scarce dimension before checking it inside the caller's transaction. */
final class BookingSlotGuard
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function assertAvailable(Booking $booking, int $capacity = 1, ?Booking $ignored = null): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('The booking slot guard must run inside a transaction.');
        }

        $keys = [];
        if ($booking->getPlanningCode() !== null) {
            $keys[] = 'planning:'.$booking->getPlanningCode();
        }
        if ($booking->getStaffMember()?->getId() !== null) {
            $keys[] = 'staff:'.$booking->getStaffMember()->getId();
        }
        if ($booking->getResourceCode() !== null) {
            $keys[] = 'resource:'.$booking->getResourceCode();
        }
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $this->connection->executeStatement('INSERT IGNORE INTO momeo_booking_lock (lock_key) VALUES (?)', [$key]);
            $this->connection->fetchOne('SELECT lock_key FROM momeo_booking_lock WHERE lock_key = ? FOR UPDATE', [$key]);
        }

        $start = $booking->getSlotStart();
        $end = $booking->getSlotEnd();
        if ($booking->getPlanningCode() !== null && $this->countOverlap('planning_code', $booking->getPlanningCode(), $start, $end, $ignored) >= max(1, $capacity)) {
            throw new SlotUnavailable('La capacité de ce créneau vient d’être atteinte.');
        }
        if ($booking->getStaffMember()?->getId() !== null && $this->countOverlap('staff_member_id', $booking->getStaffMember()->getId(), $start, $end, $ignored) > 0) {
            throw new SlotUnavailable('Ce collaborateur vient d’être réservé sur ce créneau.');
        }
        if ($booking->getResourceCode() !== null && $this->countOverlap('resource_code', $booking->getResourceCode(), $start, $end, $ignored) > 0) {
            throw new SlotUnavailable('La ressource de ce créneau vient d’être réservée.');
        }
    }

    private function countOverlap(string $column, string|int $value, \DateTimeImmutable $start, \DateTimeImmutable $end, ?Booking $ignored): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM momeo_booking WHERE %s = ? AND status IN (?, ?) AND slot_start < ? AND slot_end > ?', $column);
        $parameters = [$value, Booking::STATUS_CONFIRMED, Booking::STATUS_AWAITING_PAYMENT, $end, $start];
        $types = [is_int($value) ? Types::INTEGER : Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE];
        if ($ignored?->getId() !== null) {
            $sql .= ' AND id != ?';
            $parameters[] = $ignored->getId();
            $types[] = Types::INTEGER;
        }

        return (int) $this->connection->fetchOne($sql, $parameters, $types);
    }
}
