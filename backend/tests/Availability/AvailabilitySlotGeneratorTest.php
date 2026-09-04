<?php

declare(strict_types=1);

namespace App\Tests\Availability;

use App\Availability\AvailabilitySlotGenerator;
use PHPUnit\Framework\TestCase;

final class AvailabilitySlotGeneratorTest extends TestCase
{
    private AvailabilitySlotGenerator $generator;
    private \DateTimeZone $timezone;

    protected function setUp(): void
    {
        $this->generator = new AvailabilitySlotGenerator();
        $this->timezone = new \DateTimeZone('Europe/Paris');
    }

    public function testItUsesTheCenterTimezoneAcrossDaylightSavingTime(): void
    {
        $planning = $this->planning([
            '2027-03-27' => ['09:00'],
            '2027-03-28' => ['02:30', '09:00'],
            '2027-10-31' => ['09:00'],
        ]);

        $slots = $this->generator->generate([$planning], 'service', 60, $this->date('2027-03-27'), $this->date('2027-10-31'), new \DateTimeImmutable('2027-01-01T00:00:00Z'), $this->timezone);
        $byLocalDate = [];
        foreach ($slots as $slot) {
            $byLocalDate[$slot['localStart']->format('Y-m-d H:i')] = $slot['start']->format('H:i');
        }

        self::assertSame('08:00', $byLocalDate['2027-03-27 09:00']);
        self::assertArrayNotHasKey('2027-03-28 02:30', $byLocalDate, 'A nonexistent local time must not be normalised into an open slot.');
        self::assertSame('07:00', $byLocalDate['2027-03-28 09:00']);
        self::assertSame('08:00', $byLocalDate['2027-10-31 09:00']);
    }

    public function testItExcludesPastSlotsFiltersServicesAndDeduplicatesStarts(): void
    {
        $first = $this->planning(['2027-05-03' => ['09:00', '11:00']]);
        $duplicate = $this->planning(['2027-05-03' => ['11:00']], ['other-service']);
        $duplicate['code'] = 'planning_b';

        $slots = $this->generator->generate([$first, $duplicate], 'service', 45, $this->date('2027-05-03'), $this->date('2027-05-03'), new \DateTimeImmutable('2027-05-03T08:30:00Z'), $this->timezone);

        self::assertCount(1, $slots);
        self::assertSame('2027-05-03T09:00:00+00:00', $slots[0]['start']->format(\DateTimeInterface::ATOM));
        self::assertSame(45 * 60, $slots[0]['end']->getTimestamp() - $slots[0]['start']->getTimestamp());
    }

    /** @param array<string, list<string>> $days @param list<string> $services */
    private function planning(array $days, array $services = []): array
    {
        return ['code' => 'planning_a', 'days' => $days, 'openDays' => [], 'times' => [], 'serviceCodes' => $services];
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date.' 00:00:00', $this->timezone);
    }
}
