<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Entity\Booking;

/**
 * Explicit allowlists for each API audience. Never derive a public view from admin data.
 * publicBooking is the historical token-protected shop response (including the name).
 * client is for the authenticated owner; adminHistory is the admin client directory.
 * Access checks and policy queries belong to the caller, not this projection.
 */
final class BookingView
{
    /** @return array<string, mixed> */
    public function publicBooking(Booking $booking): array
    {
        $customerName = trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName());
        return [
            'id' => $booking->getPublicToken(),
            'reference' => $booking->getReference(),
            'status' => $booking->getStatus(),
            'source' => $booking->getSource(),
            'serviceCode' => $booking->getServiceCode(),
            'serviceName' => $booking->getServiceName(),
            'planningCode' => $booking->getPlanningCode(),
            'resourceCode' => $booking->getResourceCode(),
            'jumpTypeId' => $booking->getServiceCode(),
            'jumpTypeName' => $booking->getServiceName(),
            'customerName' => $customerName,
            'jumperName' => $customerName,
            'staffMemberId' => $booking->getStaffMember()?->getId(),
            'staffName' => $booking->getStaffName(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'options' => $booking->getOptions(),
            'paymentState' => $booking->getPaymentState(),
            'orderNumber' => $booking->getOrderNumber(),
            'amount' => $booking->getAmount(),
            'totalAmount' => $booking->getTotalAmount(),
            'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(),
        ];
    }

    /** @return array<string, mixed> */
    public function admin(Booking $booking): array
    {
        $customerName = trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName());
        return [
            'id' => $booking->getId(),
            'publicId' => $booking->getPublicToken(),
            'reference' => $booking->getReference(),
            'status' => $booking->getStatus(),
            'source' => $booking->getSource(),
            'serviceCode' => $booking->getServiceCode(),
            'serviceName' => $booking->getServiceName(),
            'planningCode' => $booking->getPlanningCode(),
            'resourceCode' => $booking->getResourceCode(),
            'jumpTypeId' => $booking->getServiceCode(),
            'jumpTypeName' => $booking->getServiceName(),
            'customerName' => $customerName,
            'jumperName' => $customerName,
            'customerEmail' => $booking->getCustomerEmail(),
            'customerPhone' => $booking->getCustomerPhone(),
            'customerNotes' => $booking->getCustomerNotes(),
            'staffMemberId' => $booking->getStaffMember()?->getId(),
            'staffName' => $booking->getStaffName(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'orderNumber' => $booking->getOrderNumber(),
            'voucherCode' => $booking->getVoucherCode(),
            'options' => $booking->getOptions(),
            'amount' => $booking->getAmount(),
            'totalAmount' => $booking->getTotalAmount(),
            'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(),
            'paymentState' => $booking->getPaymentState(),
            'postponedReason' => $booking->getPostponedReason(),
            'createdAt' => $booking->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $booking->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function client(Booking $booking, array $changePolicy): array
    {
        $name = trim($booking->getCustomerFirstName().' '.$booking->getCustomerLastName());

        return [
            'id' => $booking->getPublicToken(), 'reference' => $booking->getReference(),
            'status' => $booking->getStatus(), 'source' => $booking->getSource(),
            'jumpTypeId' => $booking->getServiceCode(), 'jumpTypeName' => $booking->getServiceName(),
            'jumperName' => $name, 'customerName' => $name, 'staffName' => $booking->getStaffName(),
            'resourceCode' => $booking->getResourceCode(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'options' => $booking->getOptions(), 'paymentState' => $booking->getPaymentState(),
            'orderNumber' => $booking->getOrderNumber(), 'amount' => $booking->getAmount(),
            'totalAmount' => $booking->getTotalAmount(), 'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(), 'postponedReason' => $booking->getPostponedReason(),
            'changeHistory' => $booking->getChangeHistory(), 'changePolicy' => $changePolicy,
        ];
    }

    /** @return array<string, mixed> */
    public function adminHistory(Booking $booking): array
    {
        return [
            'id' => $booking->getId(),
            'reference' => $booking->getReference(),
            'status' => $booking->getStatus(),
            'source' => $booking->getSource(),
            'serviceCode' => $booking->getServiceCode(),
            'serviceName' => $booking->getServiceName(),
            'staffName' => $booking->getStaffName(),
            'slotStart' => $booking->getSlotStart()->format(\DateTimeInterface::ATOM),
            'slotEnd' => $booking->getSlotEnd()->format(\DateTimeInterface::ATOM),
            'amount' => $booking->getAmount(),
            'totalAmount' => $booking->getTotalAmount(),
            'balanceDue' => $booking->getBalanceDue(),
            'currencyCode' => $booking->getCurrencyCode(),
            'paymentState' => $booking->getPaymentState(),
        ];
    }
}
