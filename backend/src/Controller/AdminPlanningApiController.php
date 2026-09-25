<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Planning\PlanningView;
use App\Entity\Planning;
use App\Service\Planning\PlanningManagementService;
use App\Service\Planning\InvalidPlanningInput;
use App\Repository\PlanningRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/plannings')]
final class AdminPlanningApiController
{
    public function __construct(
        private readonly PlanningRepository $repository,
        private readonly PlanningManagementService $management,
        private readonly PlanningView $view,
    ) {
    }

    #[Route('', name: 'momeo_api_admin_planning_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(['member' => array_map($this->view->admin(...), $this->repository->findForAdministration())]);
    }

    #[Route('/{code}', name: 'momeo_api_admin_planning_show', methods: ['GET'])]
    public function show(string $code): JsonResponse
    {
        $planning = $this->repository->findOneBy(['code' => $code]);
        return $planning instanceof Planning
            ? new JsonResponse($this->view->admin($planning))
            : new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('', name: 'momeo_api_admin_planning_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $planning = $this->management->create($this->payload($request));
        } catch (InvalidPlanningInput $error) {
            return new JsonResponse(['error' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->view->admin($planning), Response::HTTP_CREATED);
    }

    #[Route('/{code}', name: 'momeo_api_admin_planning_update', methods: ['PUT', 'PATCH'])]
    public function update(string $code, Request $request): JsonResponse
    {
        $planning = $this->repository->findOneBy(['code' => $code]);
        if (!$planning instanceof Planning) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }
        try {
            $this->management->update($planning, $this->payload($request));
        } catch (InvalidPlanningInput $error) {
            return new JsonResponse(['error' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->view->admin($planning));
    }

    #[Route('/{code}', name: 'momeo_api_admin_planning_delete', methods: ['DELETE'])]
    public function delete(string $code): Response
    {
        $planning = $this->repository->findOneBy(['code' => $code]);
        if (!$planning instanceof Planning) {
            return new JsonResponse(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $this->management->delete($planning);
        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $value = json_decode($request->getContent(), true);
        return \is_array($value) ? $value : [];
    }
}
