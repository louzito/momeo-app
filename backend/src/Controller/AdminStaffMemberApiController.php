<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StaffMember;
use App\Entity\User\AdminUser;
use App\Repository\StaffMemberRepository;
use App\Service\Security\TeamPermission;
use App\Service\Security\TeamPermissions;
use App\Service\Staff\StaffManagementService;
use App\Service\Staff\StaffAccountService;
use App\Service\Staff\InvalidStaffInput;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/staff-members')]
final class AdminStaffMemberApiController
{
    public function __construct(
        private readonly StaffMemberRepository $repository,
        private readonly StaffManagementService $management,
        private readonly StaffAccountService $accounts,
        private readonly Security $security,
    ) {
    }

    #[Route('', name: 'momeo_api_admin_staff_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'member' => array_map($this->normalize(...), $this->repository->findForAdministration()),
        ]);
    }

    #[Route('', name: 'momeo_api_admin_staff_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $member = new StaffMember();
        try {
            $this->management->save($member, $payload);
        } catch (InvalidStaffInput $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->normalize($member), Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'momeo_api_admin_staff_update', methods: ['PUT'])]
    public function update(StaffMember $member, Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        try {
            $this->management->save($member, $payload);
        } catch (InvalidStaffInput $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->normalize($member));
    }

    #[Route('/{id<\d+>}', name: 'momeo_api_admin_staff_archive', methods: ['DELETE'])]
    public function archive(StaffMember $member): Response
    {
        $this->management->archive($member);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return \is_array($payload) ? $payload : [];
    }

    /** @return array<string, mixed> */
    private function normalize(StaffMember $member): array
    {
        $account = null;
        $currentAdmin = $this->security->getUser();
        if ($currentAdmin instanceof AdminUser && TeamPermissions::allows($currentAdmin->getTeamRole(), TeamPermission::Settings)) {
            $account = $this->accounts->linkedAccount($member);
        }

        return [
            'id' => $member->getId(),
            'firstName' => $member->getFirstName(),
            'lastName' => $member->getLastName(),
            'displayName' => trim($member->getFirstName().' '.$member->getLastName()),
            'email' => $member->getEmail(),
            'phone' => $member->getPhone(),
            'jobTitle' => $member->getJobTitle(),
            'bio' => $member->getBio(),
            'color' => $member->getColor(),
            'active' => $member->isActive(),
            'bookable' => $member->isBookable(),
            'serviceCodes' => $member->getServiceCodes(),
            'workingHours' => $member->getWorkingHours(),
            'position' => $member->getPosition(),
            'accountEmail' => $account instanceof AdminUser ? $account->getEmail() : null,
            'role' => $account instanceof AdminUser ? $account->getTeamRole()->value : null,
            'createdAt' => $member->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $member->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
