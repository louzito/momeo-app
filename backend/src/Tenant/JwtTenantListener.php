<?php

declare(strict_types=1);

namespace App\Tenant;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Isolation JWT par tenant : les cles lexik restent PARTAGEES entre centres,
 * mais chaque token emis porte une claim `tenant` et un token presente sur un
 * autre centre est rejete (marque invalide => 401).
 * Tokens historiques sans claim : consideres comme tenant par defaut (skyline).
 */
#[AsEventListener(event: Events::JWT_CREATED, method: 'onJwtCreated')]
#[AsEventListener(event: Events::JWT_DECODED, method: 'onJwtDecoded')]
final class JwtTenantListener
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $payload = $event->getData();
        $payload['tenant'] = $this->tenantContext->getSlug();
        $event->setData($payload);
    }

    public function onJwtDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $claim = $payload['tenant'] ?? null;
        $tokenTenant = \is_string($claim) && $claim !== '' ? $claim : $this->tenantContext->getDefaultSlug();
        if ($tokenTenant !== $this->tenantContext->getSlug()) {
            $event->markAsInvalid();
        }
    }
}
