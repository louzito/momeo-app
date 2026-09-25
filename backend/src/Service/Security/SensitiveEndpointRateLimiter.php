<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Service\Tenant\TenantContext;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Tenant/client counters; preserves the existing sliding expiry on each attempt. */
final class SensitiveEndpointRateLimiter
{
    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function consume(string $bucket, string $identity, int $limit, int $window): bool
    {
        $key = 'security.rate.'.hash('sha256', $this->tenantContext->getSlug().'|'.$bucket.'|'.$identity);
        $item = $this->cache->getItem($key);
        $attempts = $item->isHit() ? (int) $item->get() : 0;
        if ($attempts >= $limit) {
            return false;
        }
        $item->set($attempts + 1);
        $item->expiresAfter($window);
        $this->cache->save($item);

        return true;
    }
}
