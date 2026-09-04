<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order\Adjustment;
use App\Entity\Order\Order;
use App\Entity\Product\Product;
use App\Payment\ServicePaymentTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ShopPaymentTermsController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServicePaymentTerms $terms,
    ) {}

    #[Route('/api/v2/shop/orders/{token}/payment-terms', name: 'todatempo_shop_payment_terms', methods: ['POST'])]
    public function __invoke(string $token): JsonResponse
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $token]);
        if (!$order instanceof Order || $order->getCheckoutState() === 'completed') {
            return new JsonResponse(['error' => 'La commande est introuvable ou déjà finalisée.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $service = null;
        foreach ($order->getItems() as $item) {
            $candidate = $item->getVariant()?->getProduct();
            if ($candidate instanceof Product && (str_starts_with((string) $candidate->getCode(), 'service_') || str_starts_with((string) $candidate->getCode(), 'jump_'))) {
                if ($service instanceof Product) {
                    return new JsonResponse(['error' => 'Une commande de réservation ne peut contenir qu’une prestation.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $service = $candidate;
            }
        }
        if (!$service instanceof Product) {
            return new JsonResponse(['error' => 'La prestation de la commande est introuvable.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existingTerms = $order->getAdjustments('todatempo_payment_terms');
        if (!$existingTerms->isEmpty()) {
            $adjustment = $existingTerms->first();
            $totalAmount = $order->getTotal() - $adjustment->getAmount();
            try {
                return new JsonResponse($this->terms->calculate($service, $totalAmount));
            } catch (\DomainException $exception) {
                return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            $result = $this->terms->calculate($service, $order->getTotal());
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $difference = $result['dueNow'] - $result['totalAmount'];
        if ($difference !== 0) {
            $adjustment = new Adjustment();
            $adjustment->setType('todatempo_payment_terms');
            $adjustment->setLabel('Solde à régler sur place');
            $adjustment->setAmount($difference);
            $adjustment->setLocked(true);
            $order->addAdjustment($adjustment);
        }
        foreach ($order->getPayments() as $payment) {
            $payment->setAmount($result['dueNow']);
        }
        $this->entityManager->flush();

        return new JsonResponse($result);
    }
}
