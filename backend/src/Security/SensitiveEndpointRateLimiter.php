<?php

declare(strict_types=1);

namespace App\Security;

use App\Tenant\TenantContext;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Fixed-window protection for credential and voucher-enumeration endpoints. */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 40)]
final class SensitiveEndpointRateLimiter
{
    public function __construct(
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $rule = $this->rule($request);
        if ($rule === null) {
            return;
        }

        [$bucket, $limit, $window] = $rule;
        $identity = $request->getClientIp() ?? 'unknown';
        $key = 'security.rate.'.hash('sha256', $this->tenantContext->getSlug().'|'.$bucket.'|'.$identity);
        $item = $this->cache->getItem($key);
        $attempts = $item->isHit() ? (int) $item->get() : 0;
        if ($attempts >= $limit) {
            $event->setResponse($this->rejected($window));
            return;
        }
        $item->set($attempts + 1);
        $item->expiresAfter($window);
        $this->cache->save($item);
    }

    /** @return array{string, int, int}|null */
    private function rule(Request $request): ?array
    {
        if ($request->isMethod('POST') && preg_match('#^/api/v2/(admin/administrators/token|shop/customers/token|shop/gift-vouchers/login)$#', $request->getPathInfo())) {
            return ['login', 5, 60];
        }
        if (str_starts_with($request->getPathInfo(), '/api/v2/shop/gift-vouchers/')) {
            return ['voucher', 30, 60];
        }

        return null;
    }

    private function rejected(int $retryAfter): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'too_many_requests'],
            JsonResponse::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter, 'Cache-Control' => 'no-store'],
        );
    }
}
