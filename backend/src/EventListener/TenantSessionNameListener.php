<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Tenant\TenantContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Sessions par tenant : nom de cookie de session distinct par slug (les
 * sessions ne servent aujourd'hui qu'au panel /admin — tenant par defaut —
 * et au shop Twig Sylius, pas a l'API JWT ; on isole quand meme par principe).
 * Priorite 500 : apres TenantRequestListener (512), avant le demarrage de la
 * session (SessionListener/firewall).
 */
#[AsEventListener(event: RequestEvent::class, priority: 500)]
final class TenantSessionNameListener
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->tenantContext->isDefaultTenant()) {
            return;
        }
        if (\PHP_SESSION_ACTIVE === session_status()) {
            return;
        }
        $suffix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $this->tenantContext->getSlug()));
        ini_set('session.name', 'SBSESS' . $suffix);
    }
}
