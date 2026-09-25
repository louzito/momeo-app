<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Service\Availability\PublicStaffSlot;
use App\Entity\Booking;
use App\Entity\GiftVoucher;
use App\Entity\Order\Order;
use App\Entity\Planning;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use App\Repository\PlanningRepository;
use App\Service\Payment\ServicePaymentTerms;
use App\Service\Resource\ResourceAvailability;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/** Owns public booking transactions; successful results are returned only after commit. */
final class BookingCreationService
{
    public function __construct(
        private readonly BookingIdentity $identity,
        private readonly PublicStaffSlot $publicStaffSlot,
        private readonly BookingRepository $bookingRepository,
        private readonly PlanningRepository $planningRepository,
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BookingSlotGuard $slotGuard,
        private readonly ServicePaymentTerms $paymentTerms,
        private readonly ResourceAvailability $resourceAvailability,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function createFromOrder(array $payload, \DateTimeImmutable $start, \DateTimeImmutable $end, string $serviceCode, string $firstName, string $lastName, string $email): Booking
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
        if (!$product instanceof Product || !$product->isEnabled()) {
            throw new InvalidBooking('Cette prestation n’est plus disponible.');
        }
        $serviceName = trim((string) $product->getName()) ?: trim((string) ($payload['serviceName'] ?? $serviceCode));

        $orderToken = trim((string) ($payload['orderToken'] ?? ''));
        $order = $orderToken !== '' ? $this->entityManager->getRepository(Order::class)->findOneBy(['tokenValue' => $orderToken]) : null;
        if (!$order instanceof Order || $order->getCheckoutState() !== 'completed') {
            throw new InvalidBooking('La commande associée est introuvable ou incomplète.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $staffId = (int) ($payload['staffMemberId'] ?? 0);
            $planningCodeInput = (string) ($payload['planningCode'] ?? '');
            if ($staffId > 0) {
                $staff = $this->entityManager->find(StaffMember::class, $staffId, LockMode::PESSIMISTIC_WRITE);
                $error = $this->publicStaffSlot->validateStaffSlot($staff, $serviceCode, $start, $end, $planningCodeInput);
                if ($error !== null) {
                    throw new SlotUnavailable($error);
                }
            } else {
                $staff = $this->publicStaffSlot->chooseAutoStaff($serviceCode, $start, $end, $planningCodeInput);
                if ($staff === null) {
                    throw new SlotUnavailable('Aucun collaborateur compatible n’est disponible sur ce créneau.');
                }
            }

            $booking = new Booking();
            $this->identity->initialize($booking);
            $cardPayment = $order->getLastPayment()?->getMethod()?->getCode() === 'stripe_web_elements';
            $booking->setStatus($cardPayment ? Booking::STATUS_AWAITING_PAYMENT : Booking::STATUS_CONFIRMED);
            $booking->setSource(\in_array($payload['source'] ?? '', ['direct', 'voucher'], true) ? $payload['source'] : 'direct');
            $booking->setServiceCode($serviceCode);
            $booking->setServiceName(mb_substr($serviceName, 0, 255));
            $planning = $this->planningForBooking($payload, $serviceCode);
            if (!$planning instanceof Planning) {
                throw new SlotUnavailable('Ce créneau ne correspond plus à un planning disponible.');
            }
            $booking->setPlanningCode($planning->getCode());
            try {
                $resource = $this->resourceAvailability->choose($product, $start, $end, $this->bookingRepository->findBlockingBetween($start, $end), $this->nullableText($payload['resourceCode'] ?? null, 100));
            } catch (\DomainException $exception) {
                throw new SlotUnavailable($exception->getMessage());
            }
            $booking->setResourceCode($resource?->getCode());
            $booking->setStaffMember($staff);
            $booking->setStaffName($staff ? trim($staff->getFirstName().' '.$staff->getLastName()) : null);
            $booking->setCustomerFirstName($firstName);
            $booking->setCustomerLastName($lastName);
            $booking->setCustomerEmail($email);
            $booking->setCustomerPhone($this->nullableText($payload['customer']['phone'] ?? null, 40));
            $booking->setSmsReminderConsent(($payload['customer']['smsReminderConsent'] ?? false) === true);
            $booking->setCustomerNotes($this->nullableText($payload['customer']['notes'] ?? null));
            $booking->setSlotStart($start);
            $booking->setSlotEnd($end);
            $booking->setOrderNumber($order->getNumber());
            $booking->setVoucherCode($this->nullableText($payload['voucherCode'] ?? null, 32));
            $booking->setOptions(\is_array($payload['options'] ?? null) ? array_values($payload['options']) : []);
            $totalAmount = $order->getTotal();
            foreach ($order->getAdjustments('todatempo_payment_terms') as $paymentTermsAdjustment) {
                $totalAmount -= $paymentTermsAdjustment->getAmount();
            }
            try {
                $terms = $this->paymentTerms->calculate($product, $totalAmount);
            } catch (\DomainException $exception) {
                throw new InvalidBooking($exception->getMessage());
            }
            if ($order->getTotal() !== $terms['dueNow']) {
                throw new InvalidBooking('Le montant de la commande ne correspond pas à la règle de paiement de la prestation.');
            }
            $booking->setAmount($terms['dueNow']);
            $booking->setTotalAmount($terms['totalAmount']);
            $booking->setBalanceDue($terms['balanceDue']);
            $booking->setCurrencyCode($order->getCurrencyCode() ?? 'EUR');
            $booking->setPaymentState($order->getPaymentState());

            $this->entityManager->persist($booking);
            $this->slotGuard->assertAvailable($booking, $planning->getCapacity());
            $this->entityManager->flush();
            $connection->commit();
        } catch (InvalidBooking|SlotUnavailable $exception) {
            $connection->rollBack();
            throw $exception;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw new SlotUnavailable('Ce créneau vient d’être réservé. Choisissez-en un autre.');
        }

        return $booking;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{booking: Booking, voucher: GiftVoucher}
     */
    public function createFromVoucher(string $code, array $payload, \DateTimeImmutable $start, \DateTimeImmutable $end, string $serviceCode, string $firstName, string $lastName, string $email): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $voucher = $this->giftVoucherRepository->findOneByCodeForUpdate($code);
            if (!$voucher instanceof GiftVoucher || !$voucher->isUsable()) {
                throw new \DomainException('Ce chèque cadeau n’est pas utilisable.');
            }
            if ($serviceCode !== $voucher->getServiceCode()) {
                throw new \DomainException('Ce chèque ne correspond pas à cette prestation.');
            }
            if (strcasecmp($email, $voucher->getBeneficiaryEmail()) !== 0) {
                throw new \DomainException('Cet email ne correspond pas au bénéficiaire du chèque.');
            }

            $staffId = (int) ($payload['staffMemberId'] ?? 0);
            $planningCodeInput = (string) ($payload['planningCode'] ?? '');
            if ($staffId > 0) {
                $staff = $this->entityManager->find(StaffMember::class, $staffId, LockMode::PESSIMISTIC_WRITE);
                $error = $this->publicStaffSlot->validateStaffSlot($staff, $serviceCode, $start, $end, $planningCodeInput);
                if ($error !== null) {
                    throw new SlotUnavailable($error);
                }
            } else {
                $staff = $this->publicStaffSlot->chooseAutoStaff($serviceCode, $start, $end, $planningCodeInput);
                if ($staff === null) {
                    throw new SlotUnavailable('Aucun collaborateur compatible n’est disponible sur ce créneau.');
                }
            }

            $booking = new Booking();
            $this->identity->initialize($booking);
            $booking->setStatus(Booking::STATUS_CONFIRMED);
            $booking->setSource('voucher');
            $booking->setServiceCode($voucher->getServiceCode());
            $booking->setServiceName($voucher->getServiceName());
            $planning = $this->planningForBooking($payload, $serviceCode);
            if (!$planning instanceof Planning) {
                throw new SlotUnavailable('Ce créneau ne correspond plus à un planning disponible.');
            }
            $booking->setPlanningCode($planning->getCode());
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
            if (!$product instanceof Product) throw new \DomainException('Cette prestation n’est plus disponible.');
            $resource = $this->resourceAvailability->choose($product, $start, $end, $this->bookingRepository->findBlockingBetween($start, $end), $this->nullableText($payload['resourceCode'] ?? null, 100));
            $booking->setResourceCode($resource?->getCode());
            $booking->setStaffMember($staff);
            $booking->setStaffName($staff ? trim($staff->getFirstName().' '.$staff->getLastName()) : null);
            $booking->setCustomerFirstName($firstName);
            $booking->setCustomerLastName($lastName);
            $booking->setCustomerEmail($email);
            $booking->setCustomerPhone($this->nullableText($payload['customer']['phone'] ?? null, 40));
            $booking->setSmsReminderConsent(($payload['customer']['smsReminderConsent'] ?? false) === true);
            $booking->setCustomerNotes($this->nullableText($payload['customer']['notes'] ?? null));
            $booking->setSlotStart($start);
            $booking->setSlotEnd($end);
            $booking->setVoucherCode($voucher->getCode());
            $booking->setOptions([]);
            $booking->setAmount($voucher->getAmount());
            $booking->setCurrencyCode($voucher->getCurrencyCode());
            $booking->setPaymentState('paid');

            $voucher->setStatus(GiftVoucher::STATUS_USED);
            $voucher->setUsedAt(new \DateTimeImmutable());
            $voucher->setUsageOrderNumber($booking->getReference());
            $this->entityManager->persist($booking);
            $this->slotGuard->assertAvailable($booking, $planning->getCapacity());
            $this->entityManager->flush();
            $connection->commit();
        } catch (SlotUnavailable $exception) {
            $connection->rollBack();
            throw $exception;
        } catch (\DomainException $exception) {
            $connection->rollBack();
            throw $exception;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw new SlotUnavailable('Ce créneau vient d’être réservé. Le chèque reste disponible.');
        }

        return ['booking' => $booking, 'voucher' => $voucher];
    }

    /** @param array<string, mixed> $payload */
    private function planningForBooking(array $payload, string $serviceCode): ?Planning
    {
        $code = trim((string) ($payload['planningCode'] ?? ''));
        $planning = $code === '' ? null : $this->planningRepository->findOneBy(['code' => $code]);
        if (!$planning instanceof Planning || !$planning->isActive()) {
            return null;
        }

        return $planning->getServiceCodes() === [] || \in_array($serviceCode, $planning->getServiceCodes(), true) ? $planning : null;
    }

    private function nullableText(mixed $value, ?int $length = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        return $length === null ? $value : mb_substr($value, 0, $length);
    }
}
