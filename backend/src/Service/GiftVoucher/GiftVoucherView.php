<?php

declare(strict_types=1);

namespace App\Service\GiftVoucher;

use App\Entity\Booking;
use App\Entity\GiftVoucher;
use App\Repository\BookingRepository;

/** Projections historiques shop/admin ; l’email acheteur reste réservé à l’admin. */
final class GiftVoucherView
{
    public function __construct(private readonly BookingRepository $bookingRepository)
    {
    }

    /** @return array<string, mixed> */
    public function shop(GiftVoucher $voucher): array
    {
        $booking = null;
        if ($voucher->getUsageOrderNumber() !== null) {
            $usedBooking = $this->bookingRepository->findOneBy(['reference' => $voucher->getUsageOrderNumber()]);
            if ($usedBooking instanceof Booking) {
                $booking = [
                    'reference' => $usedBooking->getReference(),
                    'jumpTypeName' => $usedBooking->getServiceName(),
                    'slotStart' => $usedBooking->getSlotStart()->format(\DateTimeInterface::ATOM),
                    'slotEnd' => $usedBooking->getSlotEnd()->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        return [
            'code' => $voucher->getCode(),
            'status' => $voucher->getEffectiveStatus(),
            'serviceCode' => $voucher->getServiceCode(),
            'serviceName' => $voucher->getServiceName(),
            'jumpTypeCode' => $voucher->getServiceCode(),
            'jumpTypeName' => $voucher->getServiceName(),
            'amount' => $voucher->getAmount(),
            'currencyCode' => $voucher->getCurrencyCode(),
            'beneficiaryName' => $voucher->getBeneficiaryName(),
            'beneficiaryEmail' => $voucher->getBeneficiaryEmail(),
            'personalMessage' => $voucher->getPersonalMessage(),
            'purchaserName' => $voucher->getPurchaserName(),
            'expiresAt' => $voucher->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'booking' => $booking,
        ];
    }

    /** @return array<string, mixed> */
    public function admin(GiftVoucher $voucher): array
    {
        return [
            'code' => $voucher->getCode(),
            'status' => $voucher->getEffectiveStatus(),
            'serviceCode' => $voucher->getServiceCode(),
            'serviceName' => $voucher->getServiceName(),
            'jumpTypeCode' => $voucher->getServiceCode(),
            'jumpTypeName' => $voucher->getServiceName(),
            'amount' => $voucher->getAmount(),
            'currencyCode' => $voucher->getCurrencyCode(),
            'purchaserName' => $voucher->getPurchaserName(),
            'purchaserEmail' => $voucher->getPurchaserEmail(),
            'beneficiaryName' => $voucher->getBeneficiaryName(),
            'beneficiaryEmail' => $voucher->getBeneficiaryEmail(),
            'personalMessage' => $voucher->getPersonalMessage(),
            'purchaseOrderNumber' => $voucher->getPurchaseOrderNumber(),
            'usageOrderNumber' => $voucher->getUsageOrderNumber(),
            'expiresAt' => $voucher->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'createdAt' => $voucher->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'activatedAt' => $voucher->getActivatedAt()?->format(\DateTimeInterface::ATOM),
            'usedAt' => $voucher->getUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
