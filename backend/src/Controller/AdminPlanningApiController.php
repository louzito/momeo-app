<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planning;
use App\Entity\StaffMember;
use App\Planning\PlanningInput;
use App\Repository\PlanningRepository;
use App\Repository\StaffMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/plannings')]
final class AdminPlanningApiController
{
    public function __construct(
        private readonly PlanningRepository $repository,
        private readonly StaffMemberRepository $staffRepository,
        private readonly PlanningInput $input,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'momeo_api_admin_planning_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(['member' => array_map($this->normalize(...), $this->repository->findForAdministration())]);
    }

    #[Route('/{code}', name: 'momeo_api_admin_planning_show', methods: ['GET'])]
    public function show(string $code): JsonResponse
    {
        $planning = $this->repository->findOneBy(['code' => $code]);
        return $planning instanceof Planning
            ? new JsonResponse($this->normalize($planning))
            : new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('', name: 'momeo_api_admin_planning_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $planning = new Planning();
        $payload = $this->payload($request);
        $code = trim((string) ($payload['code'] ?? '')) ?: $this->code((string) ($payload['name'] ?? ''));
        if ($code === '' || $this->repository->findOneBy(['code' => $code]) instanceof Planning) {
            return new JsonResponse(['error' => 'Le code du planning existe déjà ou est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $planning->setCode($code);
        if ($error = $this->hydrate($planning, $payload)) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->entityManager->persist($planning);
        $this->entityManager->flush();

        return new JsonResponse($this->normalize($planning), Response::HTTP_CREATED);
    }

    #[Route('/{code}', name: 'momeo_api_admin_planning_update', methods: ['PUT', 'PATCH'])]
    public function update(string $code, Request $request): JsonResponse
    {
        $planning = $this->repository->findOneBy(['code' => $code]);
        if (!$planning instanceof Planning) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($error = $this->hydrate($planning, $this->payload($request))) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->entityManager->flush();

        return new JsonResponse($this->normalize($planning));
    }

    #[Route('/{code}', name: 'momeo_api_admin_planning_delete', methods: ['DELETE'])]
    public function delete(string $code): Response
    {
        $planning = $this->repository->findOneBy(['code' => $code]);
        if (!$planning instanceof Planning) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $this->entityManager->remove($planning);
        $this->entityManager->flush();
        return new Response(status: Response::HTTP_NO_CONTENT);
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

    /** @return array<string, mixed> */
    private function normalize(Planning $planning): array
    {
        $staff = $planning->getStaffMember();
        $legacy = $planning->getLegacyConfig() ?? [];
        $legacyDays = $legacy['days'] ?? [];
        return ['id' => $planning->getId(), 'code' => $planning->getCode(), 'name' => $planning->getName(),
            'timezone' => $planning->getTimezone(), 'staffMemberId' => $staff?->getId(),
            'scope' => $staff ? 'staff' : 'establishment', 'weeklyDays' => $planning->getDays(),
            'days' => $legacyDays, 'openDays' => $legacy['openDays'] ?? [], 'times' => $legacy['times'] ?? [], 'capacity' => $planning->getCapacity(),
            'serviceCodes' => $planning->getServiceCodes(), 'jumpCodes' => $planning->getServiceCodes(),
            'active' => $planning->isActive(), 'createdAt' => $planning->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $planning->getUpdatedAt()->format(\DateTimeInterface::ATOM)];
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $value = json_decode($request->getContent(), true);
        return \is_array($value) ? $value : [];
    }

    private function code(string $name): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name));
        return trim('planning_'.trim($slug, '_'), '_');
    }
}
