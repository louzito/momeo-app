<?php

declare(strict_types=1);

namespace App\Staff;

final class WorkingHours
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** Accept legacy single ranges without changing their availability. */
    public static function normalize(mixed $input): array
    {
        if (!\is_array($input)) {
            throw new \InvalidArgumentException('Les disponibilités doivent être une liste de créneaux par jour.');
        }
        $result = [];
        foreach (self::DAYS as $day) {
            $row = $input[$day] ?? [];
            if (!\is_array($row)) {
                throw new \InvalidArgumentException('Disponibilités invalides : '.$day.'.');
            }
            if (array_key_exists('enabled', $row)) {
                $row = $row['enabled'] ? [['start' => $row['start'] ?? '09:00', 'end' => $row['end'] ?? '18:00']] : [];
            }
            if (!array_is_list($row)) {
                throw new \InvalidArgumentException('Les disponibilités doivent contenir une liste de créneaux par jour.');
            }
            $ranges = [];
            foreach ($row as $range) {
                if (!\is_array($range) || !\is_string($range['start'] ?? null) || !\is_string($range['end'] ?? null)
                    || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $range['start'])
                    || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $range['end'])) {
                    throw new \InvalidArgumentException('Renseignez une heure de début et de fin valides.');
                }
                if ($range['end'] <= $range['start']) {
                    throw new \InvalidArgumentException('L’heure de fin doit être postérieure à l’heure de début.');
                }
                $ranges[] = ['start' => $range['start'], 'end' => $range['end']];
            }
            usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            foreach ($ranges as $i => $range) {
                if ($i > 0 && $range['start'] < $ranges[$i - 1]['end']) {
                    throw new \InvalidArgumentException('Deux créneaux se chevauchent sur un même jour. Modifiez les horaires.');
                }
            }
            $result[$day] = $ranges;
        }
        return $result;
    }

    public static function contains(array $hours, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $timezone): bool
    {
        $start = $start->setTimezone($timezone);
        $end = $end->setTimezone($timezone);
        if ($end <= $start || $start->format('Y-m-d') !== $end->format('Y-m-d')) return false;
        try {
            $ranges = self::normalize($hours)[strtolower($start->format('l'))];
        } catch (\InvalidArgumentException) {
            return false;
        }
        foreach ($ranges as $range) {
            $opening = new \DateTimeImmutable($start->format('Y-m-d').' '.$range['start'], $timezone);
            $closing = new \DateTimeImmutable($start->format('Y-m-d').' '.$range['end'], $timezone);
            if ($start >= $opening && $end <= $closing) return true;
        }
        return false;
    }
}
