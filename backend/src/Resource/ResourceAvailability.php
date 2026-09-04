<?php

declare(strict_types=1);

namespace App\Resource;

use App\Entity\BookableResource;
use App\Entity\Booking;
use App\Entity\Product\Product;
use App\Repository\BookableResourceRepository;
use App\Availability\CenterTimeZoneProvider;
use App\Booking\BookingRules;

final class ResourceAvailability
{
    public function __construct(private readonly BookableResourceRepository $repository, private readonly CenterTimeZoneProvider $timeZoneProvider, private readonly BookingRules $bookingRules)
    {
    }

    /** @param list<Booking> $blocking
     *  @return list<string>
     */
    public function availableCodes(Product $product, \DateTimeImmutable $start, \DateTimeImmutable $end, array $blocking, ?Booking $ignored = null): array
    {
        $available = [];
        foreach ($product->getBookableResourceCodes() as $code) {
            try {
                if ($this->choose($product, $start, $end, $blocking, $code, $ignored) instanceof BookableResource) {
                    $available[] = $code;
                }
            } catch (\DomainException) {
                // Another compatible resource may still be available.
            }
        }

        return $available;
    }

    /** @param list<Booking> $blocking */
    public function choose(Product $product, \DateTimeImmutable $start, \DateTimeImmutable $end, array $blocking, ?string $requestedCode = null, ?Booking $ignored = null): ?BookableResource
    {
        $codes = $product->getBookableResourceCodes();
        if ($requestedCode !== null && !\in_array($requestedCode, $codes, true)) {
            throw new \DomainException('Cette ressource n’est pas associée à la prestation.');
        }
        if ($requestedCode === null && !$product->isBookableResourceRequired()) {
            return null;
        }

        $candidates = $requestedCode !== null ? [$requestedCode] : $codes;
        $rules = $this->bookingRules->get();
        $blockingStart = $start->modify(sprintf('-%d minutes', $rules['bufferBeforeMinutes']));
        $blockingEnd = $end->modify(sprintf('+%d minutes', $rules['bufferAfterMinutes']));
        foreach ($candidates as $code) {
            $resource = $this->repository->findOneBy(['code' => $code, 'active' => true]);
            if (!$resource instanceof BookableResource || !$this->isInsideCalendar($resource, $start, $end)) {
                continue;
            }
            $used = 0;
            foreach ($blocking as $booking) {
                if ($ignored !== null && ($booking === $ignored || ($ignored->getId() !== null && $booking->getId() === $ignored->getId()))) {
                    continue;
                }
                if ($booking->getResourceCode() === $code && $booking->getSlotStart() < $blockingEnd && $booking->getSlotEnd() > $blockingStart) {
                    ++$used;
                }
            }
            if ($used < $resource->getCapacity()) {
                return $resource;
            }
        }

        if ($product->isBookableResourceRequired() || $requestedCode !== null) {
            throw new \DomainException('Aucune ressource compatible n’est disponible sur ce créneau.');
        }

        return null;
    }

    public function isInsideCalendar(BookableResource $resource, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $localStart = $start->setTimezone($this->timeZoneProvider->get());
        $localEnd = $end->setTimezone($this->timeZoneProvider->get());
        if ($localStart->format('Y-m-d') !== $localEnd->format('Y-m-d')) {
            return false;
        }
        foreach ($resource->getCalendar()[strtolower($localStart->format('l'))] ?? [] as $range) {
            if ($localStart->format('H:i') >= $range['start'] && $localEnd->format('H:i') <= $range['end']) {
                return true;
            }
        }

        return false;
    }
}
