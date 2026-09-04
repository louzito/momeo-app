<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User\AdminUser;
use App\Security\TeamPermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AdminTeamSessionController
{
    #[Route('/api/v2/admin/team/session', name: 'todatempo_api_admin_team_session', methods: ['GET'])]
    public function __invoke(Security $security): JsonResponse
    {
        $admin = $security->getUser();
        if (!$admin instanceof AdminUser) {
            return new JsonResponse(['error' => 'unauthenticated'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $staff = $admin->getStaffMember();

        return new JsonResponse([
            'email' => $admin->getEmail(),
            'name' => $staff !== null ? trim($staff->getFirstName().' '.$staff->getLastName()) : $admin->getUsername(),
            'role' => $admin->getTeamRole()->value,
            'permissions' => TeamPermissions::forRole($admin->getTeamRole()),
            'staffMemberId' => $staff?->getId(),
        ]);
    }
}
