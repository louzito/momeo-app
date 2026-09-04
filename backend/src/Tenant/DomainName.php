<?php

declare(strict_types=1);

namespace App\Tenant;

final class DomainName
{
    public static function normalize(string $value): string
    {
        $host = strtolower(rtrim(trim($value), '.'));
        if ($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Le domaine personnalisé est invalide.');
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                throw new \InvalidArgumentException('Le domaine personnalisé est invalide.');
            }
        }
        if (!str_contains($host, '.')) {
            throw new \InvalidArgumentException('Le domaine personnalisé doit être un nom DNS complet.');
        }

        return $host;
    }
}
