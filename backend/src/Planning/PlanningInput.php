<?php

declare(strict_types=1);

namespace App\Planning;

final class PlanningInput
{
    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @param array<string, mixed> $payload
     *  @return array{days: array<string, list<array{start: string, end: string}>>, legacyConfig: ?array, error: ?string}
     */
    public function normalizeDays(array $payload): array
    {
        $input = \is_array($payload['days'] ?? null) && $payload['days'] !== []
            ? $payload['days']
            : ($payload['weeklyDays'] ?? $payload['days'] ?? []);
        if (!\is_array($input)) {
            return ['days' => [], 'legacyConfig' => null, 'error' => 'Les jours du planning sont invalides.'];
        }

        // Compatibility with the former calendar UI. Exact dates are retained verbatim;
        // weekly rules are inferred without deleting the source information.
        $dateBased = $input !== [] && array_reduce(array_keys($input), static fn (bool $ok, mixed $key): bool => $ok && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $key) === 1, true);
        if ($dateBased) {
            $weekly = [];
            foreach ($input as $date => $times) {
                if (!\is_array($times) || \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date) === false) {
                    return ['days' => [], 'legacyConfig' => null, 'error' => 'Une date du planning est invalide.'];
                }
                $weekday = strtolower((new \DateTimeImmutable((string) $date))->format('l'));
                foreach ($times as $time) {
                    $time = (string) $time;
                    if (!$this->validTime($time)) {
                        return ['days' => [], 'legacyConfig' => null, 'error' => 'Une heure du planning est invalide (format HH:MM attendu).'];
                    }
                    $end = (new \DateTimeImmutable('2000-01-01 '.$time))->modify('+1 hour')->format('H:i');
                    if ($end === '00:00') {
                        $end = '23:59';
                    }
                    $weekly[$weekday][] = ['start' => $time, 'end' => $end];
                }
            }
            foreach ($weekly as &$ranges) {
                $ranges = array_values(array_unique($ranges, SORT_REGULAR));
                usort($ranges, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
            }

            return ['days' => $weekly, 'legacyConfig' => ['days' => $input], 'error' => null];
        }

        if ($input === [] && \is_array($payload['openDays'] ?? null) && \is_array($payload['times'] ?? null)) {
            $numberToDay = [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday'];
            $days = [];
            foreach ($payload['openDays'] as $number) {
                if (!isset($numberToDay[(int) $number])) return ['days' => [], 'legacyConfig' => null, 'error' => 'Un jour du planning est invalide.'];
                foreach ($payload['times'] as $time) {
                    $time = (string) $time;
                    if (!$this->validTime($time)) return ['days' => [], 'legacyConfig' => null, 'error' => 'Une heure du planning est invalide (format HH:MM attendu).'];
                    $end = (new \DateTimeImmutable('2000-01-01 '.$time))->modify('+1 hour')->format('H:i');
                    $days[$numberToDay[(int) $number]][] = ['start' => $time, 'end' => $end === '00:00' ? '23:59' : $end];
                }
            }
            return ['days' => $days, 'legacyConfig' => ['openDays' => array_values($payload['openDays']), 'times' => array_values($payload['times'])], 'error' => null];
        }

        $days = [];
        foreach ($input as $weekday => $ranges) {
            $weekday = strtolower((string) $weekday);
            if (!\in_array($weekday, self::WEEKDAYS, true) || !\is_array($ranges)) {
                return ['days' => [], 'legacyConfig' => null, 'error' => 'Un jour du planning est invalide.'];
            }
            foreach ($ranges as $range) {
                if (!\is_array($range) || !$this->validTime((string) ($range['start'] ?? '')) || !$this->validTime((string) ($range['end'] ?? ''))) {
                    return ['days' => [], 'legacyConfig' => null, 'error' => 'Une plage horaire est invalide (format HH:MM attendu).'];
                }
                $start = (string) $range['start'];
                $end = (string) $range['end'];
                if ($end <= $start) {
                    return ['days' => [], 'legacyConfig' => null, 'error' => 'L’heure de fin doit être après l’heure de début.'];
                }
                $days[$weekday][] = ['start' => $start, 'end' => $end];
            }
            usort($days[$weekday], static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
            for ($i = 1; $i < \count($days[$weekday]); ++$i) {
                if ($days[$weekday][$i]['start'] < $days[$weekday][$i - 1]['end']) {
                    return ['days' => [], 'legacyConfig' => null, 'error' => 'Les plages horaires ne peuvent pas se chevaucher.'];
                }
            }
        }

        return ['days' => $days, 'legacyConfig' => null, 'error' => null];
    }

    private function validTime(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
