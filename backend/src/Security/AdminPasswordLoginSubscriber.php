<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

// Après le routeur, avant le firewall : aucune route héritée ne peut émettre un JWT par mot de passe.
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final class AdminPasswordLoginSubscriber
{
    public function __invoke(RequestEvent $event): void
    {
        if (preg_match('#^/api/v2/admin/administrators/token/?$#', $event->getRequest()->getPathInfo())) {
            throw new AccessDeniedHttpException('Connectez-vous sur le site TodaTempo puis utilisez « Ouvrir mon espace ».');
        }
    }
}
