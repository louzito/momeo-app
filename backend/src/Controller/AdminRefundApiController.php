<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\RefundOperation;
use App\Entity\User\AdminUser;
use App\Payment\RefundProvider;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\CurrentUser;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\Registry;

#[Route('/api/v2/admin/payments')]
final class AdminRefundApiController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefundProvider $provider,
        private readonly Registry $workflows,
    ) {}

    #[Route('/{id<\d+>}/refunds', name: 'todatempo_admin_payment_refunds', methods: ['GET'])]
    public function index(Payment $payment): JsonResponse
    {
        $operations = $this->entityManager->getRepository(RefundOperation::class)->findBy(['payment' => $payment], ['id' => 'DESC']);
        return new JsonResponse(['member' => array_map(static fn (RefundOperation $operation): array => $operation->normalize(), $operations), 'refundableAmount' => $payment->getRefundableAmount()]);
    }

    #[Route('/{id<\d+>}/refunds', name: 'todatempo_admin_payment_refund', methods: ['POST'])]
    public function create(Payment $payment, Request $request, #[CurrentUser] AdminUser $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $key = trim((string) ($request->headers->get('Idempotency-Key') ?: ($payload['idempotencyKey'] ?? '')));
        $amount = filter_var($payload['amount'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string) ($payload['reason'] ?? '')) ?: null;
        if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) return new JsonResponse(['error' => 'Une cle d’idempotence valide est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        if ($amount === false || $amount <= 0) return new JsonResponse(['error' => 'Le montant doit etre exprime en centimes et etre positif.'], Response::HTTP_UNPROCESSABLE_ENTITY);

        $existing = $this->entityManager->getRepository(RefundOperation::class)->findOneBy(['idempotencyKey' => $key]);
        if ($existing instanceof RefundOperation && !$existing->belongsTo($payment)) return new JsonResponse(['error' => 'Cette cle d’idempotence est deja utilisee.'], Response::HTTP_CONFLICT);
        if ($existing instanceof RefundOperation && $existing->getStatus() === 'completed') return new JsonResponse($existing->normalize());

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($payment);
            $existing = $this->entityManager->getRepository(RefundOperation::class)->findOneBy(['idempotencyKey' => $key]);
            if ($existing instanceof RefundOperation && (!$existing->belongsTo($payment) || $existing->getAmount() !== $amount)) throw new \DomainException('Cette cle d’idempotence est deja utilisee pour une autre demande.');
            if ($existing instanceof RefundOperation && $existing->getStatus() === 'completed') { $connection->commit(); return new JsonResponse($existing->normalize()); }
            $order = $payment->getOrder();
            if (!$order instanceof Order || $payment->getState() !== 'completed') throw new \DomainException('Seul un paiement encaisse peut etre rembourse.');
            if ($amount > $payment->getRefundableAmount()) throw new \DomainException('Le montant depasse le solde remboursable.');

            $method = $payment->getMethod();
            $providerName = (string) ($method?->getCode() ?? 'unknown');
            $actor = (string) ($user->getEmail() ?? $user->getUsername() ?? 'admin');
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
            return new JsonResponse($operation->normalize(), Response::HTTP_CREATED);
        } catch (\DomainException $exception) {
            $connection->rollBack();
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable) {
            $connection->rollBack();
            return new JsonResponse(['error' => 'Le fournisseur n’a pas pu effectuer le remboursement. Aucun etat local n’a ete modifie.'], Response::HTTP_BAD_GATEWAY);
        }
    }
}
