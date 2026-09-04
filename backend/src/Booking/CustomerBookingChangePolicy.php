<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Booking;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;

final class CustomerBookingChangePolicy
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly BookingRules $bookingRules)
    {
    }

    /** @return array{cancelHours: int, rescheduleHours: int} */
    public function limits(): array
    {
        foreach (['todatempo_config', 'skybook_config'] as $code) {
            $taxon = $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => $code]);
            $config = json_decode($taxon?->getTranslation('en_US')?->getDescription() ?: '{}', true);
            if (\is_array($config)) {
                $rules = \is_array($config['bookingChanges'] ?? null) ? $config['bookingChanges'] : [];
                return [
                    'cancelHours' => $this->bookingRules->get()['cancellationNoticeHours'],
                    'rescheduleHours' => $this->hours($rules['rescheduleHours'] ?? 24),
                ];
            }
        }

        return ['cancelHours' => 24, 'rescheduleHours' => 24];
    }

    public function assertAllowed(Booking $booking, string $action, ?\DateTimeImmutable $now = null): void
    {
        if ($booking->getStatus() !== Booking::STATUS_CONFIRMED) {
            throw new \DomainException('Seule une réservation confirmée peut être modifiée.');
        }
        $hours = $this->limits()[$action === 'cancel' ? 'cancelHours' : 'rescheduleHours'];
        $deadline = $booking->getSlotStart()->modify(sprintf('-%d hours', $hours));
        if (($now ?? new \DateTimeImmutable()) >= $deadline) {
            throw new \DomainException(sprintf('Le délai de modification de %d heure(s) avant le rendez-vous est dépassé.', $hours));
        }
    }

    private function hours(mixed $value): int
    {
        return max(0, min(8760, (int) $value));
    }
}
