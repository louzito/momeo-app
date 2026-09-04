<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Product\Product;

final class ServicePaymentTerms
{
    public const NONE = 'none';
    public const FIXED = 'fixed';
    public const PERCENTAGE = 'percentage';
    public const FULL = 'full';

    /** @return array{mode: string, value: int, totalAmount: int, dueNow: int, balanceDue: int} */
    public function calculate(Product $product, int $totalAmount): array
    {
        $mode = (string) ($this->attribute($product, 'todatempo_payment_mode') ?? self::FULL);
        $value = (int) ($this->attribute($product, 'todatempo_payment_value') ?? 0);

        return $this->calculateAmounts($mode, $value, $totalAmount);
    }

    /** @return array{mode: string, value: int, totalAmount: int, dueNow: int, balanceDue: int} */
    public function calculateAmounts(string $mode, int $value, int $totalAmount): array
    {
        if ($totalAmount < 0) {
            throw new \DomainException('Le montant de la commande est invalide.');
        }
        if (!\in_array($mode, [self::NONE, self::FIXED, self::PERCENTAGE, self::FULL], true)) {
            throw new \DomainException('La règle de paiement de cette prestation est invalide.');
        }
        if ($value < 0 || ($mode === self::PERCENTAGE && ($value < 1 || $value > 100)) || ($mode === self::FIXED && $value < 1)) {
            throw new \DomainException('Le montant de l’acompte de cette prestation est invalide.');
        }

        $dueNow = match ($mode) {
            self::NONE => 0,
            self::FIXED => min($value, $totalAmount),
            self::PERCENTAGE => min($totalAmount, intdiv(($totalAmount * $value) + 50, 100)),
            self::FULL => $totalAmount,
        };

        return compact('mode', 'value', 'totalAmount', 'dueNow') + ['balanceDue' => $totalAmount - $dueNow];
    }

    private function attribute(Product $product, string $code): mixed
    {
        foreach ($product->getAttributes() as $attributeValue) {
            if ($attributeValue->getAttribute()?->getCode() === $code) {
                return $attributeValue->getValue();
            }
        }

        return null;
    }
}
