<?php

declare(strict_types=1);

namespace App\Service\Resource;

use App\Entity\BookableResource;
use App\Entity\Product\Product;
use App\Service\Planning\PlanningInput;
use App\Repository\BookableResourceRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Mutations use the current tenant EntityManager and retain one flush per operation. */
final class BookableResourceManagementService
{
    public function __construct(
        private readonly BookableResourceRepository $repository,
        private readonly PlanningInput $input,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): BookableResource
    {
        $resource = new BookableResource();
        $code = trim((string) ($payload['code'] ?? '')) ?: $this->code((string) ($payload['name'] ?? ''));
        if ($code === '' || $this->repository->findOneBy(['code' => $code]) instanceof BookableResource) {
            throw new InvalidBookableResourceInput('Le code de la ressource existe déjà ou est invalide.');
        }
        $resource->setCode($code);
        if ($error = $this->hydrate($resource, $payload)) {
            throw new InvalidBookableResourceInput($error);
        }
        $this->entityManager->persist($resource);
        $this->entityManager->flush();

        return $resource;
    }

    /** @param array<string, mixed> $payload */
    public function update(BookableResource $resource, array $payload): void
    {
        if ($error = $this->hydrate($resource, $payload)) {
            throw new InvalidBookableResourceInput($error);
        }
        $this->entityManager->flush();
    }

    public function delete(BookableResource $resource): void
    {
        $used = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM momeo_booking WHERE resource_code = ?', [$resource->getCode()]);
        if ($used > 0) {
            $resource->setActive(false);
        } else {
            $this->entityManager->remove($resource);
        }
        $this->entityManager->flush();
    }

    public function findProduct(string $code): ?Product
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $code]);
        return $product instanceof Product ? $product : null;
    }

    /** @param array<string, mixed> $payload
     *  @return list<string>
     */
    public function assignToProduct(Product $product, array $payload): array
    {
        $codes = array_values(array_unique(array_filter(array_map('strval', \is_array($payload['codes'] ?? null) ? $payload['codes'] : []))));
        foreach ($codes as $resourceCode) {
            if (!$this->repository->findOneBy(['code' => $resourceCode]) instanceof BookableResource) {
                throw new InvalidBookableResourceInput(sprintf('La ressource « %s » est introuvable.', $resourceCode));
            }
        }
        if (($payload['required'] ?? false) && $codes === []) {
            throw new InvalidBookableResourceInput('Une ressource obligatoire doit avoir au moins une ressource compatible.');
        }
        $product->setBookableResourceCodes($codes);
        $product->setBookableResourceRequired((bool) ($payload['required'] ?? false));
        $this->entityManager->flush();
        return $codes;
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

    private function code(string $name): string { $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)); return trim('resource_'.trim($slug, '_'), '_'); }
}
