<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BookableResource;
use App\Entity\Product\Product;
use App\Planning\PlanningInput;
use App\Repository\BookableResourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin')]
final class AdminBookableResourceApiController
{
    public function __construct(
        private readonly BookableResourceRepository $repository,
        private readonly PlanningInput $input,
        private readonly EntityManagerInterface $entityManager,
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
        $resource = new BookableResource();
        $payload = $this->payload($request);
        $code = trim((string) ($payload['code'] ?? '')) ?: $this->code((string) ($payload['name'] ?? ''));
        if ($code === '' || $this->repository->findOneBy(['code' => $code]) instanceof BookableResource) {
            return new JsonResponse(['error' => 'Le code de la ressource existe déjà ou est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $resource->setCode($code);
        if ($error = $this->hydrate($resource, $payload)) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->entityManager->persist($resource);
        $this->entityManager->flush();

        return new JsonResponse($this->normalize($resource), Response::HTTP_CREATED);
    }

    #[Route('/bookable-resources/{code}', methods: ['PUT'])]
    public function update(string $code, Request $request): JsonResponse
    {
        $resource = $this->repository->findOneBy(['code' => $code]);
        if (!$resource instanceof BookableResource) return new JsonResponse(['error' => 'Ressource introuvable.'], Response::HTTP_NOT_FOUND);
        if ($error = $this->hydrate($resource, $this->payload($request))) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->entityManager->flush();
        return new JsonResponse($this->normalize($resource));
    }

    #[Route('/bookable-resources/{code}', methods: ['DELETE'])]
    public function delete(string $code): Response
    {
        $resource = $this->repository->findOneBy(['code' => $code]);
        if (!$resource instanceof BookableResource) return new JsonResponse(['error' => 'Ressource introuvable.'], Response::HTTP_NOT_FOUND);
        $used = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM momeo_booking WHERE resource_code = ?', [$code]);
        if ($used > 0) {
            $resource->setActive(false);
        } else {
            $this->entityManager->remove($resource);
        }
        $this->entityManager->flush();
        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/services/{code}/bookable-resources', methods: ['GET'])]
    public function service(string $code): JsonResponse
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $code]);
        if (!$product instanceof Product) return new JsonResponse(['error' => 'Prestation introuvable.'], Response::HTTP_NOT_FOUND);
        return new JsonResponse(['codes' => $product->getBookableResourceCodes(), 'required' => $product->isBookableResourceRequired()]);
    }

    #[Route('/services/{code}/bookable-resources', methods: ['PUT'])]
    public function updateService(string $code, Request $request): JsonResponse
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $code]);
        if (!$product instanceof Product) return new JsonResponse(['error' => 'Prestation introuvable.'], Response::HTTP_NOT_FOUND);
        $payload = $this->payload($request);
        $codes = array_values(array_unique(array_filter(array_map('strval', \is_array($payload['codes'] ?? null) ? $payload['codes'] : []))));
        foreach ($codes as $resourceCode) {
            if (!$this->repository->findOneBy(['code' => $resourceCode]) instanceof BookableResource) {
                return new JsonResponse(['error' => sprintf('La ressource « %s » est introuvable.', $resourceCode)], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        if (($payload['required'] ?? false) && $codes === []) {
            return new JsonResponse(['error' => 'Une ressource obligatoire doit avoir au moins une ressource compatible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $product->setBookableResourceCodes($codes);
        $product->setBookableResourceRequired((bool) ($payload['required'] ?? false));
        $this->entityManager->flush();
        return new JsonResponse(['codes' => $codes, 'required' => $product->isBookableResourceRequired()]);
    }

    /** @param array<string, mixed> $payload */
    private function hydrate(BookableResource $resource, array $payload): ?string
    {
        $name = mb_substr(trim((string) ($payload['name'] ?? '')), 0, 255);
        $type = (string) ($payload['type'] ?? 'room');
        $capacity = (int) ($payload['capacity'] ?? 0);
        if ($name === '') return 'Le nom est obligatoire.';
        if (!\in_array($type, BookableResource::TYPES, true)) return 'Le type de ressource est invalide.';
        if ($capacity < 1) return 'La capacité doit être supérieure ou égale à 1.';
        $normalized = $this->input->normalizeDays(['weeklyDays' => $payload['calendar'] ?? []]);
        if ($normalized['error'] !== null) return $normalized['error'];
        if ($normalized['days'] === []) return 'Au moins une plage de disponibilité est obligatoire.';
        $resource->setName($name); $resource->setType($type); $resource->setCapacity($capacity);
        $resource->setCalendar($normalized['days']); $resource->setActive((bool) ($payload['active'] ?? true));
        return null;
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
    private function code(string $name): string { $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)); return trim('resource_'.trim($slug, '_'), '_'); }
}
