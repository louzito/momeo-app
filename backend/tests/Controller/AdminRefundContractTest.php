<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Payment\RefundFixture;
use PHPUnit\Framework\TestCase;

final class AdminRefundContractTest extends TestCase
{
    use RefundFixture;

    public function testConfiguredProviderSupportsManualRefundsAndRejectsUnconfiguredStripe(): void
    {
        $payment = new \App\Entity\Payment\Payment();
        $method = new \App\Entity\Payment\PaymentMethod();
        $method->setCode('cash');
        $payment->setMethod($method);
        $provider = new \App\Service\Payment\ConfiguredRefundProvider();
        self::assertInstanceOf(\App\Service\Payment\RefundProvider::class, $provider);
        self::assertSame(['provider' => 'cash', 'reference' => 'manual-refund-42'], $provider->refund($payment, 100, 'refund-42'));
        $method->setCode('stripe_web_elements');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Stripe n’est pas configure pour ce centre.');
        $provider->refund($payment, 100, 'refund-42');
    }

    private function request(array $payload, ?string $key = 'refund-01'): \Symfony\Component\HttpFoundation\Request
    {
        $request = \Symfony\Component\HttpFoundation\Request::create('/api/v2/admin/payments/1/refunds', 'POST', content: json_encode($payload));
        if ($key !== null) $request->headers->set('Idempotency-Key', $key);
        return $request;
    }

    public function testHttpCreateReplayAndListHaveExactProjection(): void
    {
        [$service, $payment, , , $provider] = $this->fixture();
        $controller = new \App\Controller\AdminRefundApiController($service, new \App\Service\Payment\RefundView());
        $user = new \App\Entity\User\AdminUser();
        $user->setEmail('owner@example.test');
        $response = $controller->create($payment, $this->request(['amount' => 300, 'reason' => ' reason ', 'idempotencyKey' => 'ignored-key']), $user);
        self::assertSame(201, $response->getStatusCode());
        $operation = $service->operations($payment)[0];
        $expected = [
            'id' => null, 'idempotencyKey' => 'refund-01', 'amount' => 300, 'currency' => 'EUR',
            'status' => 'completed', 'provider' => 'unknown', 'providerReference' => 'remote-42',
            'creditNoteNumber' => 'AV-ORDER-42-'.strtoupper(substr(hash('sha256', 'refund-01'), 0, 8)),
            'actor' => 'owner@example.test', 'reason' => 'reason',
            'createdAt' => $operation->getCreatedAt()->format(DATE_ATOM),
            'completedAt' => $operation->getCompletedAt()->format(DATE_ATOM),
        ];
        self::assertSame($expected, json_decode($response->getContent(), true));
        $replay = $controller->create($payment, $this->request(['amount' => 999]), $user);
        self::assertSame(200, $replay->getStatusCode());
        self::assertSame($response->getContent(), $replay->getContent());
        self::assertSame(['member' => [$expected], 'refundableAmount' => 700], json_decode($controller->index($payment)->getContent(), true));
        self::assertCount(1, $provider->calls);
    }

    public function testHttpValidationConflictAndProviderErrors(): void
    {
        [$service, $payment, , , $provider] = $this->fixture();
        $controller = new \App\Controller\AdminRefundApiController($service, new \App\Service\Payment\RefundView());
        $user = new \App\Entity\User\AdminUser();
        foreach ([
            [[], null, 422, 'Une cle d’idempotence valide est obligatoire.'],
            [['amount' => 0], 'refund-01', 422, 'Le montant doit etre exprime en centimes et etre positif.'],
            [['amount' => 1001], 'refund-01', 409, 'Le montant depasse le solde remboursable.'],
        ] as [$payload, $key, $status, $message]) {
            $response = $controller->create($payment, $this->request($payload, $key), $user);
            self::assertSame($status, $response->getStatusCode());
            self::assertSame(['error' => $message], json_decode($response->getContent(), true));
        }
        self::assertSame([], $provider->calls);
        $provider->failure = new \RuntimeException('timeout');
        $response = $controller->create($payment, $this->request(['amount' => 300, 'idempotencyKey' => 'refund-01'], null), $user);
        self::assertSame(502, $response->getStatusCode());
        self::assertSame(['error' => 'Le fournisseur n’a pas pu effectuer le remboursement. Aucun etat local n’a ete modifie.'], json_decode($response->getContent(), true));
        $provider->failure = new \DomainException('Stripe n’est pas configure pour ce centre.');
        self::assertSame(409, $controller->create($payment, $this->request(['amount' => 300]), $user)->getStatusCode());
    }

    public function testKeyForAnotherPaymentIsConflictEvenWhenCompleted(): void
    {
        [$service, $payment, $order, , $provider, , $connection, $operations] = $this->fixture();
        $operation = new \App\Entity\Payment\RefundOperation(new \App\Entity\Payment\Payment(), $order, 'refund-01', 300, 'EUR', 'cash', 'owner', null);
        $operation->complete('remote', 'credit');
        $operations['refund-01'] = $operation;
        $connection->expects(self::never())->method('beginTransaction');
        $controller = new \App\Controller\AdminRefundApiController($service, new \App\Service\Payment\RefundView());
        $response = $controller->create($payment, $this->request(['amount' => 300]), new \App\Entity\User\AdminUser());
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['error' => 'Cette cle d’idempotence est deja utilisee.'], json_decode($response->getContent(), true));
        self::assertSame([], $provider->calls);
    }
}
