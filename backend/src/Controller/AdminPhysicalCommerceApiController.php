<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order\Order;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin')]
final class AdminPhysicalCommerceApiController
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    #[Route('/products/{code}/commerce', methods: ['PUT'])]
    public function product(string $code, Request $request): JsonResponse
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $code]);
        if (!$product instanceof Product) return new JsonResponse(['error' => 'Produit introuvable.'], Response::HTTP_NOT_FOUND);
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        try { $product->setTodatempoType((string) ($payload['type'] ?? 'service')); }
        catch (\InvalidArgumentException $e) { return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY); }
        $product->setPickupEnabled($product->isPhysical() && (bool) ($payload['pickupEnabled'] ?? false));
        $product->setDeliveryEnabled($product->isPhysical() && (bool) ($payload['deliveryEnabled'] ?? false));
        $product->setDeliveryFee((int) ($payload['deliveryFee'] ?? 0));
        if ($product->isPhysical() && !$product->isPickupEnabled() && !$product->isDeliveryEnabled()) {
            return new JsonResponse(['error' => 'Activez au moins un mode de remise.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant) continue;
            $variant->setShippingRequired($product->isPhysical());
            $variant->setTracked($product->isPhysical());
            if (isset($payload['stock'])) $variant->setOnHand(max(0, (int) $payload['stock']));
        }
        $this->entityManager->flush();
        return new JsonResponse(['code' => $code, 'type' => $product->getTodatempoType()]);
    }

    #[Route('/orders/{tokenValue}/preparation', methods: ['PATCH'])]
    public function preparation(string $tokenValue, Request $request): JsonResponse
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $tokenValue]);
        if (!$order instanceof Order || $order->getPreparationState() === null) return new JsonResponse(['error' => 'Commande physique introuvable.'], Response::HTTP_NOT_FOUND);
        $payload = json_decode($request->getContent(), true);
        $state = \is_array($payload) ? (string) ($payload['state'] ?? '') : '';
        $allowed = [Order::PREPARATION_PENDING, Order::PREPARATION_PREPARING, Order::PREPARATION_READY, Order::PREPARATION_HANDED_OVER];
        if (!\in_array($state, $allowed, true)) return new JsonResponse(['error' => 'Statut de préparation invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        $order->setPreparationState($state);
        $this->entityManager->flush();
        return new JsonResponse(['state' => $state]);
    }
}
