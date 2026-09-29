<?php
namespace App\Controller;
use App\Service\Commerce\UnifiedCheckoutService;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
final class ShopCheckoutController
{
    public function __construct(private readonly UnifiedCheckoutService $checkout) {}
    #[Route('/api/v2/shop/checkout', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            if (strlen($request->getContent()) > 50000) throw new \InvalidArgumentException('Panier trop volumineux.');
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) throw new \InvalidArgumentException('Panier invalide.');
            return new JsonResponse($this->checkout->checkout($data), 200, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException|\JsonException $e) {
            return new JsonResponse(['error' => $e instanceof \JsonException ? 'Panier invalide.' : $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }
    }
}
