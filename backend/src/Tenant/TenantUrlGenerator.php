<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Generates public URLs from the registry, never from an untrusted Host header. */
final class TenantUrlGenerator
{
    public function __construct(
        private readonly TenantRegistry $registry,
        #[Autowire('%todatempo.public_base_url%')] private readonly string $fallbackBaseUrl,
    ) {
    }

    public function baseUrl(string $slug): string
    {
        $domain = $this->registry->verifiedDomainFor($slug);
        if ($domain !== null) {
            // The V1 front-end keeps the tenant slug in its route contract.
            return 'https://'.$domain.'/'.rawurlencode($slug);
        }

        return rtrim($this->fallbackBaseUrl, '/').'/'.rawurlencode($slug);
    }

    public function url(string $slug, string $path = ''): string
    {
        return $this->baseUrl($slug).'/'.ltrim($path, '/');
    }
}
