<?php

declare(strict_types=1);
namespace App\Controller;

use App\Service\GiftCard\GiftCardPaymentService;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop/gift-cards')]
final class ShopGiftCardPaymentController
{
    public function __construct(private readonly GiftCardPaymentService $payments) {}

    #[Route('/balance', methods: ['POST'])]
    #[Route('/prepare', methods: ['POST'])]
    #[Route('/settle', methods: ['POST'])]
    public function payment(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        try {
            if (!is_array($data) || !is_string($data['code'] ?? '') || !is_string($data['orderToken'] ?? '')) throw new \InvalidArgumentException('Demande invalide.');
            $result = match (basename($request->getPathInfo())) {
                'balance' => $this->payments->quote($data['code'] ?? ''),
                'prepare' => $this->payments->prepare($data['orderToken'] ?? '', $data['code'] ?? ''),
                default => $this->payments->settle($data['orderToken'] ?? ''),
            };
            return new JsonResponse($result, 200, ['Cache-Control' => 'no-store']);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422, ['Cache-Control' => 'no-store']);
        }
    }
}
