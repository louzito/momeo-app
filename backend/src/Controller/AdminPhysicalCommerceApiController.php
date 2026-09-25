<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Commerce\PhysicalProductManagementService;
use App\Service\Commerce\InvalidPhysicalCommerce;
use App\Service\Commerce\PhysicalPreparationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin')]
final class AdminPhysicalCommerceApiController
{
    public function __construct(
        private readonly PhysicalProductManagementService $products,
        private readonly PhysicalPreparationService $preparation,
    ) {}

    #[Route('/products/{code}/commerce', methods: ['PUT'])]
    public function product(string $code, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        try {
            $result = $this->products->update($code, \is_array($payload) ? $payload : []);
        } catch (InvalidPhysicalCommerce $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return $result === null
            ? new JsonResponse(['error' => 'Produit introuvable.'], Response::HTTP_NOT_FOUND)
            : new JsonResponse($result);
    }

    #[Route('/orders/{tokenValue}/preparation', methods: ['PATCH'])]
    public function preparation(string $tokenValue, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $state = \is_array($payload) ? (string) ($payload['state'] ?? '') : '';
        try {
            $result = $this->preparation->update($tokenValue, $state);
        } catch (InvalidPhysicalCommerce $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return $result === null
            ? new JsonResponse(['error' => 'Commande physique introuvable.'], Response::HTTP_NOT_FOUND)
            : new JsonResponse($result);
    }
}
