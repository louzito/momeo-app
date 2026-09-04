<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Tenant\TenantContext;
use App\Tenant\TenantIdentifierResolver;
use App\Tenant\TenantRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resout le tenant depuis X-TodaTempo-Tenant, avec lecture temporaire de
 * X-Skybook-Tenant — Sylius ne voit jamais le prefixe /{slug} des URLs.
 * Slug inconnu ou desactive => 404 immediat, AVANT router/session/firewall
 * (priorite 512). Pas d'en-tete => tenant par defaut (/admin, /_profiler).
 *
 * Ceinture-bretelles : si une connexion DBAL s'est deja ouverte avant nous
 * (ex. detection de plateforme si server_version manque), on la FERME apres
 * avoir pose le slug — la prochaine requete SQL reconnectera sur la bonne BDD
 * via TenantConnectionMiddleware. Sans transaction a ce stade (priorite 512),
 * c'est sans risque.
 */
#[AsEventListener(event: RequestEvent::class, priority: 512)]
final class TenantRequestListener
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantIdentifierResolver $identifierResolver,
        private readonly TenantRegistry $registry,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $slug = $this->identifierResolver->fromRequest($event->getRequest());
        if ($slug === null) {
            return;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug) || !$this->registry->isServable($slug)) {
            throw new NotFoundHttpException(sprintf('Centre inconnu : "%s".', $slug));
        }
        $previous = $this->tenantContext->getSlug();
        $this->tenantContext->setSlug($slug);
        if ($previous !== $slug && $this->connection->isConnected()) {
            $this->connection->close();
        }
    }
}
