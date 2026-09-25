<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Payment\Payment;
use App\Entity\Payment\RefundOperation;
use App\Entity\User\AdminUser;
use App\Service\Payment\RefundService;
use App\Service\Payment\RefundView;
use App\Service\Payment\RefundFailed;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\CurrentUser;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/payments')]
final class AdminRefundApiController
{
    public function __construct(
        private readonly RefundService $refunds,
        private readonly RefundView $view,
    ) {}

    #[Route('/{id<\d+>}/refunds', name: 'todatempo_admin_payment_refunds', methods: ['GET'])]
    public function index(Payment $payment): JsonResponse
    {
        $operations = $this->refunds->operations($payment);
        return new JsonResponse(['member' => array_map(fn (RefundOperation $operation): array => $this->view->normalize($operation), $operations), 'refundableAmount' => $payment->getRefundableAmount()]);
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

        try {
            $result = $this->refunds->refund($payment, $amount, $key, (string) ($user->getEmail() ?? $user->getUsername() ?? 'admin'), $reason);
            return new JsonResponse($this->view->normalize($result['operation']), $result['replayed'] ? Response::HTTP_OK : Response::HTTP_CREATED);
        } catch (\DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_CONFLICT);
        } catch (RefundFailed) {
            return new JsonResponse(['error' => 'Le fournisseur n’a pas pu effectuer le remboursement. Aucun etat local n’a ete modifie.'], Response::HTTP_BAD_GATEWAY);
        }
    }
}
