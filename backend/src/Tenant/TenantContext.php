<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tenant courant. Resolution :
 *   1. slug pose explicitement (TenantRequestListener via X-Skybook-Tenant) ;
 *   2. variable d'env SKYBOOK_TENANT (CLI : `SKYBOOK_TENANT=slug bin/console ...`) ;
 *   3. tenant par defaut (%skybook.default_tenant%) — UNIQUEMENT pour ne pas
 *      casser /admin (panel Sylius), /_profiler et les commandes legacy.
 */
final class TenantContext
{
    private ?string $slug = null;

    public function __construct(
        private readonly TenantRegistry $registry,
        #[Autowire('%skybook.default_tenant%')] private readonly string $defaultTenant,
    ) {
    }

    public function setSlug(?string $slug): void
    {
        $this->slug = $slug;
    }

    public function getSlug(): string
    {
        if ($this->slug !== null) {
            return $this->slug;
        }
        $env = $_SERVER['SKYBOOK_TENANT'] ?? $_ENV['SKYBOOK_TENANT'] ?? getenv('SKYBOOK_TENANT');
        if (\is_string($env) && $env !== '') {
            return $env;
        }

        return $this->defaultTenant;
    }

    public function isDefaultTenant(): bool
    {
        return $this->getSlug() === $this->defaultTenant;
    }

    public function getDefaultSlug(): string
    {
        return $this->defaultTenant;
    }

    /** Nom de la BDD du tenant courant (jamais derive du slug : registre). */
    public function getDatabaseName(): string
    {
        $slug = $this->getSlug();
        $db = $this->registry->databaseFor($slug);
        if ($db === null) {
            throw new \RuntimeException(sprintf('TodaTempo : établissement inconnu "%s" (absent de config/tenants.json).', $slug));
        }

        return $db;
    }
}
