<?php

declare(strict_types=1);

namespace App\Availability;

final class AvailabilitySlotGenerator
{
    /**
     * @param list<array{code: string, days: array<string, list<string>>, openDays: list<int>, times: list<string>, serviceCodes: list<string>}> $plannings
     * @return list<array{planningCode: string, localStart: \DateTimeImmutable, start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public function generate(array $plannings, string $serviceCode, int $duration, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now, \DateTimeZone $timezone): array
    {
        $slots = [];
        foreach ($plannings as $planning) {
            if ($planning['serviceCodes'] !== [] && !\in_array($serviceCode, $planning['serviceCodes'], true)) {
                continue;
            }
            for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                $times = $planning['days'] !== []
                    ? ($planning['days'][$date] ?? [])
                    : (\in_array((int) $day->format('w'), $planning['openDays'], true) ? $planning['times'] : []);
                foreach ($times as $time) {
                    $local = new \DateTimeImmutable($date.' '.$time, $timezone);
                    // PHP normalise les heures inexistantes du passage a l'heure d'ete : elles sont fermees.
                    if ($local->format('Y-m-d H:i') !== $date.' '.$time) {
                        continue;
                    }
                    $start = $local->setTimezone(new \DateTimeZone('UTC'));
                    if ($start <= $now) {
                        continue;
                    }
                    $key = $start->format('U');
                    $slots[$key] ??= ['planningCode' => $planning['code'], 'localStart' => $local, 'start' => $start, 'end' => $start->modify(sprintf('+%d minutes', $duration))];
                }
            }
        }
        ksort($slots, SORT_NUMERIC);

        return array_values($slots);
    }
}
