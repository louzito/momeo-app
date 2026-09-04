<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order\Adjustment;
use App\Entity\Order\Order;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop')]
final class ShopPhysicalOrderApiController
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

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

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $tokenValue]);
            if (!$order instanceof Order || $order->getCheckoutState() === 'completed') {
                throw new \DomainException('Ce panier est introuvable ou déjà finalisé.');
            }
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            if ($order->getItems()->isEmpty()) throw new \DomainException('Le panier est vide.');

            $deliveryFee = 0;
            foreach ($order->getItems() as $item) {
                $variant = $item->getVariant();
                if (!$variant instanceof ProductVariant) throw new \DomainException('Un article du panier est invalide.');
                $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
                $product = $variant->getProduct();
                if (!$product instanceof Product || !$product->isPhysical()) {
                    throw new \DomainException('Les produits physiques doivent être commandés séparément des prestations et options.');
                }
                if (($mode === 'pickup' && !$product->isPickupEnabled()) || ($mode === 'delivery' && !$product->isDeliveryEnabled())) {
                    throw new \DomainException('Ce mode de remise n’est pas disponible pour tous les articles.');
                }
                if (!$variant->isTracked() || $variant->getOnHand() - $variant->getOnHold() < $item->getQuantity()) {
                    throw new \DomainException(sprintf('Stock insuffisant pour « %s ».', $item->getProductName()));
                }
                $deliveryFee = max($deliveryFee, $product->getDeliveryFee());
            }
            if ($mode === 'delivery' && $order->getShippingAddress() === null) {
                throw new \DomainException('Une adresse de livraison complète est obligatoire.');
            }

            foreach ($order->getAdjustments('todatempo_delivery') as $adjustment) $order->removeAdjustment($adjustment);
            if ($mode === 'delivery' && $deliveryFee > 0) {
                $adjustment = new Adjustment();
                $adjustment->setType('todatempo_delivery');
                $adjustment->setLabel('Frais de livraison');
                $adjustment->setAmount($deliveryFee);
                $order->addAdjustment($adjustment);
            }
            $order->setFulfillmentMode($mode);
            $order->setPreparationState(Order::PREPARATION_PENDING);
            $this->entityManager->flush();
            $connection->commit();

            return new JsonResponse(['mode' => $mode, 'deliveryFee' => $mode === 'delivery' ? $deliveryFee : 0]);
        } catch (\DomainException $exception) {
            $connection->rollBack();
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'La remise de la commande n’a pas pu être configurée.'], Response::HTTP_CONFLICT);
        }
    }

    #[Route('/physical-products', methods: ['GET'])]
    public function products(): JsonResponse
    {
        $products = $this->entityManager->getRepository(Product::class)->findBy([
            'todatempoType' => Product::TYPE_PHYSICAL,
            'enabled' => true,
        ]);
        return new JsonResponse(['member' => array_map(static function (Product $product): array {
            $variant = $product->getVariants()->first();
            $pricing = $variant instanceof ProductVariant ? $variant->getChannelPricings()->first() : null;
            return [
                'code' => $product->getCode(), 'name' => $product->getName(),
                'shortDescription' => $product->getShortDescription(), 'description' => $product->getDescription(),
                'pickupEnabled' => $product->isPickupEnabled(), 'deliveryEnabled' => $product->isDeliveryEnabled(),
                'deliveryFee' => $product->getDeliveryFee(),
                'defaultVariantData' => [
                    'price' => $pricing ? $pricing->getPrice() : 0,
                    'onHand' => $variant instanceof ProductVariant ? $variant->getOnHand() : 0,
                    'onHold' => $variant instanceof ProductVariant ? $variant->getOnHold() : 0,
                ],
                'images' => array_map(static fn ($image): array => ['type' => $image->getType(), 'path' => $image->getPath()], $product->getImages()->toArray()),
            ];
        }, $products)]);
    }
}
