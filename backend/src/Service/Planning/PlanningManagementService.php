<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\Planning;
use App\Entity\StaffMember;
use App\Repository\PlanningRepository;
use App\Repository\StaffMemberRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Mutations use the current tenant EntityManager and retain one flush per operation. */
final class PlanningManagementService
{
    public function __construct(
        private readonly PlanningRepository $repository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly PlanningInput $input,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): Planning
    {
        $planning = new Planning();
        $code = trim((string) ($payload['code'] ?? '')) ?: $this->code((string) ($payload['name'] ?? ''));
        if ($code === '' || $this->repository->findOneBy(['code' => $code]) instanceof Planning) {
            throw new InvalidPlanningInput('Le code du planning existe déjà ou est invalide.');
        }
        $planning->setCode($code);
        if ($error = $this->hydrate($planning, $payload)) {
            throw new InvalidPlanningInput($error);
        }
        $this->entityManager->persist($planning);
        $this->entityManager->flush();

        return $planning;
    }

    /** @param array<string, mixed> $payload */
    public function update(Planning $planning, array $payload): void
    {
        if ($error = $this->hydrate($planning, $payload)) {
            throw new InvalidPlanningInput($error);
        }
        $this->entityManager->flush();
    }

    public function delete(Planning $planning): void
    {
        $this->entityManager->remove($planning);
        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(Planning $planning, array $payload): ?string
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') return 'Le nom du planning est obligatoire.';
        $timezone = trim((string) ($payload['timezone'] ?? 'Europe/Paris'));
        try { new \DateTimeZone($timezone); } catch (\Throwable) { return 'Le fuseau horaire est invalide.'; }
        $capacity = (int) ($payload['capacity'] ?? 0);
        if ($capacity < 1) return 'La capacité doit être supérieure ou égale à 1.';

        $normalized = $this->input->normalizeDays($payload);
        if ($normalized['error'] !== null) return $normalized['error'];
        if ($normalized['days'] === []) return 'Au moins un jour et une plage horaire sont obligatoires.';

        $staff = null;
        if (isset($payload['staffMemberId']) && $payload['staffMemberId'] !== null && $payload['staffMemberId'] !== '') {
            $staff = $this->staffRepository->find((int) $payload['staffMemberId']);
            if (!$staff instanceof StaffMember) return 'Collaborateur introuvable.';
        }
        $services = \is_array($payload['serviceCodes'] ?? $payload['jumpCodes'] ?? null) ? ($payload['serviceCodes'] ?? $payload['jumpCodes']) : [];
        $planning->setName(mb_substr($name, 0, 255));
        $planning->setTimezone($timezone);
        $planning->setStaffMember($staff);
        $planning->setDays($normalized['days']);
        $planning->setCapacity($capacity);
        $planning->setServiceCodes(array_values(array_filter(array_map(static fn (mixed $v): string => mb_substr(trim((string) $v), 0, 255), $services))));
        $planning->setActive((bool) ($payload['active'] ?? true));
        $planning->setLegacyConfig($normalized['legacyConfig']);
        return null;
    }

    private function code(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name));
        return trim('planning_'.trim($slug, '_'), '_');
    }
}
