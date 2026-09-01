<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\StaffMember;
use App\Entity\StaffTimeOff;
use App\Repository\StaffMemberRepository;
use App\Repository\StaffTimeOffRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/staff-time-offs')]
final class AdminStaffTimeOffApiController
{
    public function __construct(
        private readonly StaffTimeOffRepository $repository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'momeo_api_admin_time_off_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        try {
            $from = new \DateTimeImmutable((string) $request->query->get('from', 'first day of this month'));
            $to = new \DateTimeImmutable((string) $request->query->get('to', '+3 months'));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'La période est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['member' => array_map($this->normalize(...), $this->repository->findBetween($from, $to))]);
    }

    #[Route('', name: 'momeo_api_admin_time_off_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $staff = $this->staffRepository->find((int) ($payload['staffMemberId'] ?? 0));
        if (!$staff instanceof StaffMember) {
            return new JsonResponse(['error' => 'Collaborateur introuvable.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $start = new \DateTimeImmutable((string) ($payload['start'] ?? ''));
            $end = new \DateTimeImmutable((string) ($payload['end'] ?? ''));
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'La période est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($end <= $start) {
            return new JsonResponse(['error' => 'L’heure de fin doit être après le début.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $timeOff = new StaffTimeOff();
        $timeOff->setStaffMember($staff);
        $timeOff->setStartsAt($start);
        $timeOff->setEndsAt($end);
        $timeOff->setReason(mb_substr(trim((string) ($payload['reason'] ?? 'Indisponible')) ?: 'Indisponible', 0, 255));
        $this->entityManager->persist($timeOff);
        $this->entityManager->flush();

        return new JsonResponse($this->normalize($timeOff), Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'momeo_api_admin_time_off_delete', methods: ['DELETE'])]
    public function delete(StaffTimeOff $timeOff): Response
    {
        $this->entityManager->remove($timeOff);
        $this->entityManager->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function normalize(StaffTimeOff $timeOff): array
    {
        $staff = $timeOff->getStaffMember();
        return [
            'id' => $timeOff->getId(),
            'staffMemberId' => $staff->getId(),
            'staffName' => trim($staff->getFirstName().' '.$staff->getLastName()),
            'start' => $timeOff->getStartsAt()->format(\DateTimeInterface::ATOM),
            'end' => $timeOff->getEndsAt()->format(\DateTimeInterface::ATOM),
            'reason' => $timeOff->getReason(),
        ];
    }
}
