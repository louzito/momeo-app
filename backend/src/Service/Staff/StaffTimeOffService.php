<?php

declare(strict_types=1);

namespace App\Service\Staff;

use App\Entity\StaffMember;
use App\Entity\StaffTimeOff;
use App\Repository\StaffMemberRepository;
use Doctrine\ORM\EntityManagerInterface;

final class StaffTimeOffService
{
    public function __construct(
        private readonly StaffMemberRepository $staffRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** @param array<string, mixed> $payload */
    public function create(array $payload): StaffTimeOff
    {
        $staff = $this->staffRepository->find((int) ($payload['staffMemberId'] ?? 0));
        if (!$staff instanceof StaffMember) {
            throw new InvalidStaffInput('Collaborateur introuvable.');
        }
        try {
            $start = new \DateTimeImmutable((string) ($payload['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($payload['end'] ?? ''));
        } catch (\Throwable) {
            throw new InvalidStaffInput('La période est invalide.');
        }
        if ($end <= $start) {
            throw new InvalidStaffInput('L’heure de fin doit être après le début.');
        }

        $timeOff = new StaffTimeOff();
        $timeOff->setStaffMember($staff);
        $timeOff->setStartsAt($start);
        $timeOff->setEndsAt($end);
        $timeOff->setReason(mb_substr(trim((string) ($payload['reason'] ?? 'Indisponible')) ?: 'Indisponible', 0, 255));
        $this->entityManager->persist($timeOff);
        $this->entityManager->flush();

        return $timeOff;
    }

    public function delete(StaffTimeOff $timeOff): void
    {
        $this->entityManager->remove($timeOff);
        $this->entityManager->flush();
    }
}
