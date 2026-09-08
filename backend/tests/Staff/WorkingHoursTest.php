<?php

declare(strict_types=1);

namespace App\Tests\Staff;

use App\Staff\WorkingHours;
use PHPUnit\Framework\TestCase;

final class WorkingHoursTest extends TestCase
{
    public function testBreaksAndBoundaries(): void
    {
        $hours = ['monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '19:00']]];
        $tz = new \DateTimeZone('Europe/Paris');
        foreach ([['09:00', '12:00', true], ['13:00', '19:00', true], ['12:00', '13:00', false], ['11:30', '13:30', false], ['18:30', '19:30', false], ['08:30', '09:30', false]] as [$start, $end, $expected]) {
            self::assertSame($expected, WorkingHours::contains($hours, new \DateTimeImmutable('2026-09-07 '.$start, $tz), new \DateTimeImmutable('2026-09-07 '.$end, $tz), $tz));
        }
        self::assertFalse(WorkingHours::contains($hours, new \DateTimeImmutable('2026-09-08 10:00', $tz), new \DateTimeImmutable('2026-09-08 11:00', $tz), $tz));
    }

    public function testLegacyHoursArePreserved(): void
    {
        $hours = WorkingHours::normalize(['monday' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], 'tuesday' => ['enabled' => false]]);
        self::assertSame([['start' => '09:00', 'end' => '18:00']], $hours['monday']);
        self::assertSame([], $hours['tuesday']);
    }

    public function testInvalidTimesAndReversedRangesAreRejected(): void
    {
        foreach ([['25:00', '26:00'], ['12:00', '09:00'], ['12:00', '12:00']] as [$start, $end]) {
            try {
                WorkingHours::normalize(['monday' => [['start' => $start, 'end' => $end]]]);
                self::fail('Invalid range was accepted.');
            } catch (\InvalidArgumentException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testUtcBookingsUseLocalHoursAndExactSeconds(): void
    {
        $hours = ['monday' => [['start' => '09:00', 'end' => '12:00']]];
        $tz = new \DateTimeZone('Europe/Paris');
        self::assertTrue(WorkingHours::contains($hours, new \DateTimeImmutable('2026-09-07T07:00:00Z'), new \DateTimeImmutable('2026-09-07T10:00:00Z'), $tz));
        self::assertFalse(WorkingHours::contains($hours, new \DateTimeImmutable('2026-09-07T07:00:00Z'), new \DateTimeImmutable('2026-09-07T10:00:01Z'), $tz));
    }

    public function testOverlapsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkingHours::normalize(['monday' => [['start' => '09:00', 'end' => '14:00'], ['start' => '13:00', 'end' => '19:00']]]);
    }
}
