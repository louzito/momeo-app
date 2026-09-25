<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Commerce\PhysicalCheckoutService;
use App\Service\Commerce\PhysicalProductReadService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop')]
final class ShopPhysicalOrderApiController
{
    public function __construct(
        private readonly PhysicalCheckoutService $checkout,
        private readonly PhysicalProductReadService $products,
    ) {}

    /** Configure la remise avant la finalisation Sylius. Aucun rendez-vous n'est créé. */
    #[Route('/orders/{tokenValue}/physical-fulfillment', methods: ['PATCH'])]
    public function configure(string $tokenValue, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $mode = (string) ($payload['mode'] ?? '');
        if (!\in_array($mode, ['pickup', 'delivery'], true)) {
            return new JsonResponse(['error' => 'Choisissez le retrait au centre ou la livraison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return new JsonResponse($this->checkout->configure($tokenValue, $mode));
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'La remise de la commande n’a pas pu être configurée.'], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/physical-products', methods: ['GET'])]
    public function products(): JsonResponse
    {
        return new JsonResponse($this->products->products());
    }
}
