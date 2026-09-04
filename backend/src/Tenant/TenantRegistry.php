<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Registre des centres (tenants) : config/tenants.json.
 * C'est LA source de verite slug -> base de donnees. Le nom de la BDD ne
 * derive PAS du slug (les BDD du pool sont creees avant de connaitre le slug
 * definitif) : renommer un slug = une ecriture dans le registre.
 *
 * Format : { "<slug>": { "db": "skybook_xxx", "name": "...", "enabled": true,
 *            "status": "active" | "pool" | "template" } }
 *
 * Statuts : `active` = centre attribue, servi publiquement ;
 * `pool` = centre blanc pre-installe en attente d'attribution ;
 * `template` = BDD modele clonee par pool-refill (jamais servie en prod).
 */
final class TenantRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $tenants = null;

    private string $loadedFingerprint = '';

    public function __construct(
        #[Autowire('%todatempo.tenants_file%')] private readonly string $file,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $fingerprint = is_file($this->file) ? (string) sha1_file($this->file) : '';
        if ($this->tenants === null || $fingerprint !== $this->loadedFingerprint) {
            $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : [];
            $this->tenants = \is_array($data) ? $data : [];
            $this->loadedFingerprint = $fingerprint;
        }

        return $this->tenants;
    }

    /** @return array<string, mixed>|null */
    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /** Le tenant existe-t-il et peut-il etre servi publiquement ? */
    public function isServable(string $slug): bool
    {
        $tenant = $this->get($slug);
        if ($tenant === null || ($tenant['enabled'] ?? true) === false) {
            return false;
        }
        // Seuls les centres `active` sont servis en prod. Les statuts
        // techniques (pool, template, installing…) ne sont accessibles qu'en
        // debug (dev), pour les smoke-tests et le provisionnement.
        if (($tenant['status'] ?? 'active') !== 'active' && !$this->debug) {
            return false;
        }

        return true;
    }

    public function databaseFor(string $slug): ?string
    {
        $db = $this->get($slug)['db'] ?? null;

        return \is_string($db) && $db !== '' ? $db : null;
    }

    public function verifiedDomainFor(string $slug): ?string
    {
        $domain = $this->get($slug)['customDomain'] ?? null;

        return \is_array($domain)
            && \is_string($domain['host'] ?? null)
            && \is_string($domain['verifiedAt'] ?? null)
            ? $domain['host'] : null;
    }

    public function slugForVerifiedDomain(string $host): ?string
    {
        try {
            $host = DomainName::normalize($host);
        } catch (\InvalidArgumentException) {
            return null;
        }
        foreach ($this->all() as $slug => $tenant) {
            if ($this->isServable($slug) && $this->verifiedDomainFor($slug) === $host) {
                return $slug;
            }
        }

        return null;
    }
}
