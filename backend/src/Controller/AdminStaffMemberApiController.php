<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StaffMember;
use App\Entity\User\AdminUser;
use App\Repository\StaffMemberRepository;
use App\Security\TeamRole;
use App\Security\TeamPermission;
use App\Security\TeamPermissions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/staff-members')]
final class AdminStaffMemberApiController
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(
        private readonly StaffMemberRepository $repository,
        private readonly EntityManagerInterface $entityManager,
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
        $error = $this->hydrate($member, $payload);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($member);
        if (($error = $this->syncAccount($member, $payload)) !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->entityManager->flush();

        return new JsonResponse($this->normalize($member), Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'momeo_api_admin_staff_update', methods: ['PUT'])]
    public function update(StaffMember $member, Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        $error = $this->hydrate($member, $payload);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (($error = $this->syncAccount($member, $payload)) !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->entityManager->flush();

        return new JsonResponse($this->normalize($member));
    }

    #[Route('/{id<\d+>}', name: 'momeo_api_admin_staff_archive', methods: ['DELETE'])]
    public function archive(StaffMember $member): Response
    {
        $member->setActive(false);
        $member->setBookable(false);
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return \is_array($payload) ? $payload : [];
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(StaffMember $member, array $payload): ?string
    {
        $firstName = trim((string) ($payload['firstName'] ?? ''));
        $lastName = trim((string) ($payload['lastName'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            return 'Le prénom et le nom sont obligatoires.';
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'L’adresse email est invalide.';
        }

        $color = trim((string) ($payload['color'] ?? '#1f5c57'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return 'La couleur doit être au format #RRGGBB.';
        }

        $member->setFirstName(mb_substr($firstName, 0, 100));
        $member->setLastName(mb_substr($lastName, 0, 100));
        $member->setEmail($email !== '' ? mb_substr($email, 0, 180) : null);
        $member->setPhone($this->nullableText($payload['phone'] ?? null, 40));
        $member->setJobTitle($this->nullableText($payload['jobTitle'] ?? null, 120));
        $member->setBio($this->nullableText($payload['bio'] ?? null));
        $member->setColor(strtolower($color));
        $member->setActive((bool) ($payload['active'] ?? true));
        $member->setBookable((bool) ($payload['bookable'] ?? true));
        $member->setPosition(max(0, (int) ($payload['position'] ?? 0)));

        $serviceCodes = \is_array($payload['serviceCodes'] ?? null) ? $payload['serviceCodes'] : [];
        $member->setServiceCodes(array_values(array_filter(array_map(
            static fn (mixed $code): string => mb_substr(trim((string) $code), 0, 255),
            $serviceCodes,
        ))));
        $member->setWorkingHours($this->normalizeWorkingHours($payload['workingHours'] ?? []));

        return null;
    }

    private function nullableText(mixed $value, ?int $length = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $length === null ? $value : mb_substr($value, 0, $length);
    }

    /** @return array<string, array{enabled: bool, start: string, end: string}> */
    private function normalizeWorkingHours(mixed $value): array
    {
        $input = \is_array($value) ? $value : [];
        $hours = [];
        foreach (self::DAYS as $day) {
            $row = \is_array($input[$day] ?? null) ? $input[$day] : [];
            $start = preg_match('/^\d{2}:\d{2}$/', (string) ($row['start'] ?? '')) ? (string) $row['start'] : '09:00';
            $end = preg_match('/^\d{2}:\d{2}$/', (string) ($row['end'] ?? '')) ? (string) $row['end'] : '18:00';
            $hours[$day] = ['enabled' => (bool) ($row['enabled'] ?? false), 'start' => $start, 'end' => $end];
        }

        return $hours;
    }

    /** @param array<string, mixed> $payload */
    private function syncAccount(StaffMember $member, array $payload): ?string
    {
        if (!array_key_exists('accountEmail', $payload) && !array_key_exists('role', $payload)) {
            return null;
        }

        $role = TeamRole::tryFrom((string) ($payload['role'] ?? TeamRole::Practitioner->value));
        if ($role === null) {
            return 'Le rôle doit être owner, manager, reception ou practitioner.';
        }

        $repository = $this->entityManager->getRepository(AdminUser::class);
        $currentlyLinked = $repository->findOneBy(['staffMember' => $member]);
        $email = mb_strtolower(trim((string) ($payload['accountEmail'] ?? '')));
        if ($email === '') {
            if ($currentlyLinked instanceof AdminUser && !$this->canRemoveOwner($currentlyLinked)) {
                return 'Le dernier propriétaire ne peut pas être dissocié.';
            }
            $currentlyLinked?->setStaffMember(null);
            return null;
        }

        $admin = $repository->findOneBy(['email' => $email]);
        if (!$admin instanceof AdminUser || !$admin->isEnabled()) {
            return 'Aucun compte actif ne correspond à cette adresse email.';
        }
        $otherLink = $repository->findOneBy(['staffMember' => $member]);
        if ($otherLink instanceof AdminUser && $otherLink !== $admin) {
            if (!$this->canRemoveOwner($otherLink)) {
                return 'Le dernier propriétaire ne peut pas être remplacé.';
            }
            $otherLink->setStaffMember(null);
        }
        if ($admin->getTeamRole() === TeamRole::Owner && $role !== TeamRole::Owner && !$this->canRemoveOwner($admin)) {
            return 'Au moins un propriétaire actif est obligatoire.';
        }

        $admin->setStaffMember($member);
        $admin->setTeamRole($role);
        return null;
    }

    private function canRemoveOwner(AdminUser $admin): bool
    {
        if ($admin->getTeamRole() !== TeamRole::Owner) {
            return true;
        }

        return count($this->entityManager->getRepository(AdminUser::class)->findBy([
            'teamRole' => TeamRole::Owner,
            'enabled' => true,
        ])) > 1;
    }

    /** @return array<string, mixed> */
    private function normalize(StaffMember $member): array
    {
        $account = null;
        $currentAdmin = $this->security->getUser();
        if ($currentAdmin instanceof AdminUser && TeamPermissions::allows($currentAdmin->getTeamRole(), TeamPermission::Settings)) {
            $account = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['staffMember' => $member]);
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
