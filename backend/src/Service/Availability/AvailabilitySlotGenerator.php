<?php

declare(strict_types=1);

namespace App\Service\Availability;

final class AvailabilitySlotGenerator
{
    /**
     * @param list<array{code: string, days: array<string, list<string>>, openDays: list<int>, times: list<string>, serviceCodes: list<string>, weeklyDays?: array<string, list<array{start: string, end: string}>>, timezone?: string, staffMemberId?: ?int}> $plannings
     * @return list<array{planningCode: string, staffMemberId: ?int, localStart: \DateTimeImmutable, start: \DateTimeImmutable, end: \DateTimeImmutable}>
     */
    public function generate(array $plannings, string $serviceCode, int $duration, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now, \DateTimeZone $timezone): array
    {
        $slots = [];
        foreach ($plannings as $planning) {
            if ($planning['serviceCodes'] !== [] && !\in_array($serviceCode, $planning['serviceCodes'], true)) {
                continue;
            }
            $planningTimezone = isset($planning['timezone']) ? new \DateTimeZone($planning['timezone']) : $timezone;
            for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                $times = $planning['days'] !== []
                    ? ($planning['days'][$date] ?? [])
                    : (\in_array((int) $day->format('w'), $planning['openDays'], true) ? $planning['times'] : []);
                $ranges = $planning['weeklyDays'][strtolower($day->format('l'))] ?? [];
                foreach ($ranges as $range) {
                    $cursor = new \DateTimeImmutable($date.' '.$range['start'], $planningTimezone);
                    $limit = new \DateTimeImmutable($date.' '.$range['end'], $planningTimezone);
                    while ($duration > 0 && $cursor->modify(sprintf('+%d minutes', $duration)) <= $limit) {
                        $times[] = $cursor->format('H:i');
                        $cursor = $cursor->modify(sprintf('+%d minutes', $duration));
                    }
                }
                foreach ($times as $time) {
                    $local = new \DateTimeImmutable($date.' '.$time, $planningTimezone);
                    // PHP normalise les heures inexistantes du passage a l'heure d'ete : elles sont fermees.
                    if ($local->format('Y-m-d H:i') !== $date.' '.$time) {
                        continue;
                    }
                    $start = $local->setTimezone(new \DateTimeZone('UTC'));
                    if ($start <= $now) {
                        continue;
                    }
                    $key = $start->format('U').'_'.$planning['code'];
                    $slots[$key] ??= ['planningCode' => $planning['code'], 'staffMemberId' => $planning['staffMemberId'] ?? null, 'localStart' => $local, 'start' => $start, 'end' => $start->modify(sprintf('+%d minutes', $duration))];
                }
            }
        }
        uasort($slots, static fn (array $a, array $b): int => [$a['start'], $a['planningCode']] <=> [$b['start'], $b['planningCode']]);

        return array_values($slots);
    }
}
