<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Order\Adjustment;
use App\Entity\Order\Order;
use App\Entity\Product\Product;
use Doctrine\ORM\EntityManagerInterface;

final class OrderPaymentTermsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ServicePaymentTerms $terms,
    ) {}

    public function apply(string $token): array
    {
        $order = $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $token]);
        if (!$order instanceof Order || $order->getCheckoutState() === 'completed') {
            throw new \DomainException('La commande est introuvable ou déjà finalisée.');
        }

        $service = null;
        foreach ($order->getItems() as $item) {
            $candidate = $item->getVariant()?->getProduct();
            if ($candidate instanceof Product && (str_starts_with((string) $candidate->getCode(), 'service_') || str_starts_with((string) $candidate->getCode(), 'jump_'))) {
                if ($service instanceof Product) {
                    throw new \DomainException('Une commande de réservation ne peut contenir qu’une prestation.');
                }
                $service = $candidate;
            }
        }
        if (!$service instanceof Product) {
            throw new \DomainException('La prestation de la commande est introuvable.');
        }

        $existingTerms = $order->getAdjustments('todatempo_payment_terms');
        if (!$existingTerms->isEmpty()) {
            $adjustment = $existingTerms->first();
            $totalAmount = $order->getTotal() - $adjustment->getAmount();
            return $this->terms->calculate($service, $totalAmount);
        }

        $result = $this->terms->calculate($service, $order->getTotal());

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

        return $result;
    }
}
