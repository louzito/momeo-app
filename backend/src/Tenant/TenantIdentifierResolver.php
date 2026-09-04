<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\HttpFoundation\Request;

/** Centralise les identifiants publics du tenant et leur priorite de transition. */
final class TenantIdentifierResolver
{
    public const HTTP_HEADER = 'X-TodaTempo-Tenant';
    public const LEGACY_HTTP_HEADER = 'X-Skybook-Tenant';
    public const ENVIRONMENT_VARIABLE = 'TODATEMPO_TENANT';
    public const LEGACY_ENVIRONMENT_VARIABLE = 'SKYBOOK_TENANT';

    public function fromRequest(Request $request): ?string
    {
        $header = $this->resolve(
            $request->headers->get(self::HTTP_HEADER),
            $request->headers->get(self::LEGACY_HTTP_HEADER),
        );
        if ($header !== null) {
            return $header;
        }

        // Stripe cannot attach our tenant header. The slug is therefore part of
        // this one webhook URL and is resolved before Symfony routing runs.
        if (preg_match('#^/api/v2/shop/payments/stripe/webhook/([a-z0-9][a-z0-9-]{0,62})$#', $request->getPathInfo(), $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function fromEnvironment(): ?string
    {
        return $this->resolve(
            $this->environmentValue(self::ENVIRONMENT_VARIABLE),
            $this->environmentValue(self::LEGACY_ENVIRONMENT_VARIABLE),
        );
    }

    private function resolve(?string $current, ?string $legacy): ?string
    {
        foreach ([$current, $legacy] as $value) {
            if ($value !== null && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return null;
    }

    private function environmentValue(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) ? $value : null;
    }
}
