<?php

declare(strict_types=1);

namespace App\Tests\Planning;

use App\Planning\PlanningInput;
use PHPUnit\Framework\TestCase;

final class PlanningInputTest extends TestCase
{
    public function testItAcceptsValidNonOverlappingRanges(): void
    {
        $result = (new PlanningInput())->normalizeDays(['weeklyDays' => [
            'monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '13:30', 'end' => '18:00']],
        ]]);

        self::assertNull($result['error']);
        self::assertSame('13:30', $result['days']['monday'][1]['start']);
    }

    /** @dataProvider invalidSchedules */
    public function testItRejectsInvalidSchedules(array $days): void
    {
        self::assertNotNull((new PlanningInput())->normalizeDays(['weeklyDays' => $days])['error']);
    }

    public static function invalidSchedules(): iterable
    {
        yield 'invalid time' => [['monday' => [['start' => '25:00', 'end' => '26:00']]]];
        yield 'reversed' => [['monday' => [['start' => '12:00', 'end' => '09:00']]]];
        yield 'overlap' => [['monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '11:59', 'end' => '13:00']]]];
        yield 'unknown day' => [['holiday' => [['start' => '09:00', 'end' => '12:00']]]];
    }

    public function testLegacyCalendarDataIsRetainedAndConverted(): void
    {
        $legacy = ['days' => ['2026-09-07' => ['09:00']]];
        $result = (new PlanningInput())->normalizeDays($legacy);

        self::assertNull($result['error']);
        self::assertSame($legacy, $result['legacyConfig']);
        self::assertSame([['start' => '09:00', 'end' => '10:00']], $result['days']['monday']);
    }
}
