<?php

declare(strict_types=1);

namespace App\Configuration;

/** Backwards-compatible reader for the versioned site editor document. */
final class SiteConfigDocument
{
    /** @param mixed $decoded @return array<string, mixed> */
    public static function published(mixed $decoded): array
    {
        if (!\is_array($decoded)) {
            return [];
        }

        return ($decoded['schemaVersion'] ?? null) === 1 && \is_array($decoded['published'] ?? null)
            ? $decoded['published']
            : $decoded;
    }
}
