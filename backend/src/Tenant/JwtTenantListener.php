<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Service\Tenant\TenantContext;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use App\Entity\User\AdminUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Isolation JWT par tenant : les cles lexik restent PARTAGEES entre centres,
 * mais chaque token emis porte une claim `tenant` et un token presente sur un
 * autre centre est rejete (marque invalide => 401).
 * Les tokens sans claim sont rejetes : accepter implicitement le tenant par
 * defaut permettrait a un ancien token de contourner la frontiere courante.
 */
#[AsEventListener(event: Events::JWT_CREATED, method: 'onJwtCreated')]
#[AsEventListener(event: Events::JWT_DECODED, method: 'onJwtDecoded')]
final class JwtTenantListener
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        #[Autowire('%env(int:TODATEMPO_ADMIN_JWT_TTL)%')] private readonly int $adminTokenTtl = 43200,
    )
    {
    }

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        if ($event->getUser() instanceof AdminUser) {
            $payload['exp'] = ($payload['iat'] ?? time()) + $this->adminTokenTtl;
        }
        $payload['tenant'] = $this->tenantContext->getSlug();
        $event->setData($payload);
    }

    public function onJwtDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $claim = $payload['tenant'] ?? null;
        if (!\is_string($claim) || $claim === '' || !hash_equals($this->tenantContext->getSlug(), $claim)) {
            $event->markAsInvalid();
        }
    }
}
