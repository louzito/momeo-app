<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\AdminUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bundle\SecurityBundle\Security;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final class AdminApiPermissionSubscriber
{
    public function __construct(private readonly Security $security) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/v2/admin/') || $this->isPublicAuthenticationPath($path)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof AdminUser) {
            return; // Le firewall produit le 401/403 d'authentification.
        }

        $permission = $this->permissionFor($path, $request->getMethod());
        if ($permission !== null && !TeamPermissions::allows($user->getTeamRole(), $permission)) {
            throw new AccessDeniedHttpException(sprintf('Permission "%s" requise.', $permission->value));
        }
    }

    private function isPublicAuthenticationPath(string $path): bool
    {
        return str_contains($path, '/administrators/token')
            || preg_match('#/admin/(?:todatempo|momeo)/sso/(?:handoff|session)$#', $path) === 1;
    }

    private function permissionFor(string $path, string $method): ?TeamPermission
    {
        $resource = substr($path, strlen('/api/v2/admin/'));

        if (preg_match('#^(bookings|plannings|staff-time-offs|waitlist)(?:/|$)#', $resource)) {
            return TeamPermission::Agenda;
        }
        if (preg_match('#^staff-members(?:/|$)#', $resource)) {
            return $method === 'GET' ? TeamPermission::Agenda : TeamPermission::Settings;
        }
        if (preg_match('#^clients(?:/|$)#', $resource)) {
            return TeamPermission::Clients;
        }
        if (preg_match('#^(orders|payments|payment-methods|payment-requests|invoices|gift-vouchers)(?:/|$)#', $resource)) {
            return TeamPermission::Finances;
        }
        if (preg_match('#^(products?|product-[^/]+|taxons?|bookable-resources|services)(?:/|$)#', $resource)) {
            return TeamPermission::Catalog;
        }
        if (preg_match('#^(channels|countries|currencies|exchange-rates|locales|shipping-[^/]+|zones|administrators|team)(?:/|$)#', $resource)) {
            return TeamPermission::Settings;
        }

        return null;
    }
}
