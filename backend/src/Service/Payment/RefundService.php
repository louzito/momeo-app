<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\RefundOperation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Workflow\Registry;

/** Orchestration sur la connexion tenant existante ; aucun retry automatique. */
final class RefundService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefundProvider $provider,
        private readonly Registry $workflows,
    ) {}

    /** @return list<RefundOperation> */
    public function operations(Payment $payment): array
    {
        return $this->entityManager->getRepository(RefundOperation::class)->findBy(['payment' => $payment], ['id' => 'DESC']);
    }

    /** @return array{operation: RefundOperation, replayed: bool} */
    public function refund(Payment $payment, int $amount, string $key, string $actor, ?string $reason): array
    {
        $existing = $this->entityManager->getRepository(RefundOperation::class)->findOneBy(['idempotencyKey' => $key]);
        if ($existing instanceof RefundOperation && !$existing->belongsTo($payment)) throw new \DomainException('Cette cle d’idempotence est deja utilisee.');
        if ($existing instanceof RefundOperation && $existing->getStatus() === 'completed') return ['operation' => $existing, 'replayed' => true];

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($payment);
            $existing = $this->entityManager->getRepository(RefundOperation::class)->findOneBy(['idempotencyKey' => $key]);
            if ($existing instanceof RefundOperation && (!$existing->belongsTo($payment) || $existing->getAmount() !== $amount)) throw new \DomainException('Cette cle d’idempotence est deja utilisee pour une autre demande.');
            if ($existing instanceof RefundOperation && $existing->getStatus() === 'completed') { $connection->commit(); return ['operation' => $existing, 'replayed' => true]; }
            $order = $payment->getOrder();
            if (!$order instanceof Order || $payment->getState() !== 'completed') throw new \DomainException('Seul un paiement encaisse peut etre rembourse.');
            if ($amount > $payment->getRefundableAmount()) throw new \DomainException('Le montant depasse le solde remboursable.');

            $method = $payment->getMethod();
            $providerName = (string) ($method?->getCode() ?? 'unknown');
            $operation = $existing instanceof RefundOperation ? $existing : new RefundOperation($payment, $order, $key, $amount, (string) $order->getCurrencyCode(), $providerName, $actor, $reason);
            if (!$existing instanceof RefundOperation) { $this->entityManager->persist($operation); $this->entityManager->flush(); }

            $result = $this->provider->refund($payment, $amount, $key);
            $newRefundedAmount = $payment->getRefundedAmount() + $amount;
            $payment->setRefundedAmount($newRefundedAmount);
            $full = $newRefundedAmount === (int) $payment->getAmount();
            if ($full) {
                $workflow = $this->workflows->get($payment, 'sylius_payment');
                if ($workflow->can($payment, 'refund')) $workflow->apply($payment, 'refund');
            }
            $order->setPaymentState($full ? 'refunded' : 'partially_refunded');
            $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['orderNumber' => $order->getNumber()]);
            if ($booking instanceof Booking) {
                $booking->setPaymentState($full ? 'refunded' : 'partially_refunded');
                if ($full) $booking->setStatus(Booking::STATUS_CANCELLED);
                $booking->recordChange(['action' => $full ? 'refunded' : 'partially_refunded', 'actor' => $actor, 'amount' => $amount, 'idempotencyKey' => $key, 'at' => (new \DateTimeImmutable())->format(DATE_ATOM)]);
            }
            $creditNote = sprintf('AV-%s-%s', $order->getNumber(), strtoupper(substr(hash('sha256', $key), 0, 8)));
            $operation->complete($result['reference'], $creditNote);
            $this->entityManager->flush();
            $connection->commit();
            return ['operation' => $operation, 'replayed' => false];
        } catch (\DomainException $exception) {
            $connection->rollBack();
            throw $exception;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw new RefundFailed(previous: $exception);
        }
    }
}
