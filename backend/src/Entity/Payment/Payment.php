<?php

declare(strict_types=1);

namespace App\Entity\Payment;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Payment as BasePayment;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_payment')]
class Payment extends BasePayment
{
    /** Montant deja rembourse, en unite mineure. Source de verite locale. */
    #[ORM\Column(name: 'refunded_amount', options: ['default' => 0])]
    private int $refundedAmount = 0;

    public function getRefundedAmount(): int { return $this->refundedAmount; }
    public function setRefundedAmount(int $amount): void { $this->refundedAmount = max(0, $amount); }
    public function getRefundableAmount(): int { return max(0, (int) $this->getAmount() - $this->refundedAmount); }
}
