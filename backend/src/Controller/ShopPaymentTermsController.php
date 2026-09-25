<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Payment\OrderPaymentTermsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShopPaymentTermsController
{
    public function __construct(private readonly OrderPaymentTermsService $terms) {}

    #[Route('/api/v2/shop/orders/{token}/payment-terms', name: 'todatempo_shop_payment_terms', methods: ['POST'])]
    public function __invoke(string $token): JsonResponse
    {
        try {
            return new JsonResponse($this->terms->apply($token));
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
