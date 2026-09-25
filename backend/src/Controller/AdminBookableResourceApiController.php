<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BookableResource;
use App\Entity\Product\Product;
use App\Service\Resource\BookableResourceManagementService;
use App\Service\Resource\InvalidBookableResourceInput;
use App\Repository\BookableResourceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin')]
final class AdminBookableResourceApiController
{
    public function __construct(
        private readonly BookableResourceRepository $repository,
        private readonly BookableResourceManagementService $management,
    ) {
    }

    #[Route('/bookable-resources', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(['member' => array_map($this->normalize(...), $this->repository->findForAdministration())]);
    }

    #[Route('/bookable-resources', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $resource = $this->management->create($this->payload($request));
        } catch (InvalidBookableResourceInput $error) {
            return new JsonResponse(['error' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->normalize($resource), Response::HTTP_CREATED);
    }

    #[Route('/bookable-resources/{code}', methods: ['PUT'])]
    public function update(string $code, Request $request): JsonResponse
    {
        $resource = $this->repository->findOneBy(['code' => $code]);
        if (!$resource instanceof BookableResource) return new JsonResponse(['error' => 'Ressource introuvable.'], Response::HTTP_NOT_FOUND);
        try {
            $this->management->update($resource, $this->payload($request));
        } catch (InvalidBookableResourceInput $error) {
            return new JsonResponse(['error' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return new JsonResponse($this->normalize($resource));
    }

    #[Route('/bookable-resources/{code}', methods: ['DELETE'])]
    public function delete(string $code): Response
    {
        $resource = $this->repository->findOneBy(['code' => $code]);
        if (!$resource instanceof BookableResource) return new JsonResponse(['error' => 'Ressource introuvable.'], Response::HTTP_NOT_FOUND);
        $this->management->delete($resource);
        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/services/{code}/bookable-resources', methods: ['GET'])]
    public function service(string $code): JsonResponse
    {
        $product = $this->management->findProduct($code);
        if (!$product instanceof Product) return new JsonResponse(['error' => 'Prestation introuvable.'], Response::HTTP_NOT_FOUND);
        return new JsonResponse(['codes' => $product->getBookableResourceCodes(), 'required' => $product->isBookableResourceRequired()]);
    }

    #[Route('/services/{code}/bookable-resources', methods: ['PUT'])]
    public function updateService(string $code, Request $request): JsonResponse
    {
        $product = $this->management->findProduct($code);
        if (!$product instanceof Product) return new JsonResponse(['error' => 'Prestation introuvable.'], Response::HTTP_NOT_FOUND);
        try {
            $codes = $this->management->assignToProduct($product, $this->payload($request));
        } catch (InvalidBookableResourceInput $error) {
            return new JsonResponse(['error' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return new JsonResponse(['codes' => $codes, 'required' => $product->isBookableResourceRequired()]);
    }

    /** @return array<string, mixed> */
    private function normalize(BookableResource $resource): array
    {
        return ['id' => $resource->getId(), 'code' => $resource->getCode(), 'name' => $resource->getName(), 'type' => $resource->getType(),
            'capacity' => $resource->getCapacity(), 'calendar' => $resource->getCalendar(), 'active' => $resource->isActive(),
            'createdAt' => $resource->getCreatedAt()->format(\DateTimeInterface::ATOM), 'updatedAt' => $resource->getUpdatedAt()->format(\DateTimeInterface::ATOM)];
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array { $data = json_decode($request->getContent(), true); return \is_array($data) ? $data : []; }
}
