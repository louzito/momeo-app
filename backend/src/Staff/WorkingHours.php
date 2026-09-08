<?php

declare(strict_types=1);

namespace App\Staff;

/** Reads legacy daily hours and the new list of weekly ranges. */
final class WorkingHours
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public static function ranges(array $value): array
    {
        if (array_is_list($value)) return $value;
        $ranges = [];
        foreach (self::DAYS as $day) {
            $row = $value[$day] ?? [];
            if ($row['enabled'] ?? false) {
                $ranges[] = ['start' => $row['start'] ?? '09:00', 'end' => $row['end'] ?? '18:00', 'days' => [$day]];
            }
        }
        return $ranges;
    }

    public static function normalize(mixed $value): array
    {
        if (!is_array($value)) throw new \DomainException('Les disponibilités doivent être une liste de créneaux.');
        if (!array_is_list($value)) {
            foreach ($value as $day => $row) {
                if (!in_array($day, self::DAYS, true) || !is_array($row)) {
                    throw new \DomainException('Le format des disponibilités est invalide.');
                }
            }
        }
        $ranges = self::ranges($value);
        foreach ($ranges as $index => $range) {
            $label = 'Créneau '.($index + 1).' : ';
            foreach (['start', 'end'] as $field) {
                if (!is_array($range) || !is_string($range[$field] ?? null) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $range[$field])) {
                    throw new \DomainException($label.'les heures doivent être valides (HH:MM).');
                }
            }
            if ($range['end'] <= $range['start']) throw new \DomainException($label.'l’heure de fin doit être postérieure à l’heure de début.');
            if (!is_array($range['days'] ?? null) || $range['days'] === []) throw new \DomainException($label.'sélectionnez au moins un jour.');
            foreach ($range['days'] as $day) {
                if (!in_array($day, self::DAYS, true)) throw new \DomainException($label.'jour invalide.');
            }
            foreach (array_slice($ranges, 0, $index) as $previous) {
                if (array_intersect($range['days'], $previous['days']) && $range['start'] < $previous['end'] && $range['end'] > $previous['start']) {
                    throw new \DomainException($label.'chevauchement avec un autre créneau sur un même jour.');
                }
            }
        }
        return array_map(static fn (array $range): array => ['start' => $range['start'], 'end' => $range['end'], 'days' => array_values(array_unique($range['days']))], $ranges);
    }

    public static function contains(array $hours, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $timezone): bool
    {
        $start = $start->setTimezone($timezone);
        $end = $end->setTimezone($timezone);
        if ($end <= $start || $start->format('Y-m-d') !== $end->format('Y-m-d')) return false;
        foreach (self::ranges($hours) as $range) {
            if (!in_array(strtolower($start->format('l')), $range['days'], true)) continue;
            $opening = new \DateTimeImmutable($start->format('Y-m-d').' '.$range['start'], $timezone);
            $closing = new \DateTimeImmutable($start->format('Y-m-d').' '.$range['end'], $timezone);
            if ($start >= $opening && $end <= $closing) return true;
        }
        return false;
    }
}
