<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GiftCard\GiftCardCheckoutService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop/gift-cards')]
final class ShopGiftCardApiController
{
    public function __construct(private readonly GiftCardCheckoutService $checkout) {}

    #[Route('/offer', methods: ['GET'])]
    public function offer(): JsonResponse
    {
        try {
            return $this->response($this->checkout->offer());
        } catch (\DomainException $e) {
            return $this->response(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/purchase', methods: ['POST'])]
    public function purchase(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_int($data['amount'] ?? null) || !is_string($data['paymentMethod'] ?? null)) {
            return $this->response(['error' => 'Le montant ou le moyen de paiement est invalide.'], 422);
        }
        try {
            $order = $this->checkout->purchase($data['amount'], $data, $data['paymentMethod']);
            return $this->response(['orderToken' => $order->getTokenValue()], 201);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return $this->response(['error' => $e->getMessage()], 422);
        }
    }

    // Le code reste dans le corps HTTP et le fragment du lien, hors des journaux d’URL.
    #[Route('/document', methods: ['POST'])]
    public function document(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        if (!is_string($code) || !preg_match('/^[A-F0-9]{32}$/D', $code)) {
            return $this->response(['error' => 'Carte cadeau introuvable.'], 404);
        }
        $document = $this->checkout->document($code);
        return $document === null ? $this->response(['error' => 'Carte cadeau introuvable.'], 404) : $this->response($document);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
