<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Booking;
use App\Entity\Payment\RefundOperation;
use App\Service\Payment\RefundFailed;
use Doctrine\DBAL\LockMode;
use PHPUnit\Framework\TestCase;

class RefundServiceTest extends TestCase
{
    use RefundFixture;

    public function testPartialThenFullAndReplayKeepActorCreditNoteAndSingleProviderCallPerKey(): void
    {
        [$service, $payment, $order, $booking, $provider, $em, $connection, , $workflow] = $this->fixture();
        $em->expects(self::exactly(2))->method('lock')->with($payment, LockMode::PESSIMISTIC_WRITE);
        $em->expects(self::exactly(2))->method('refresh')->with($payment);
        $connection->expects(self::exactly(2))->method('beginTransaction');
        $connection->expects(self::exactly(2))->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $workflow->expects(self::once())->method('can')->with($payment, 'refund')->willReturn(true);
        $workflow->expects(self::once())->method('apply')->with($payment, 'refund')->willReturn(new \Symfony\Component\Workflow\Marking());
        $first = $service->refund($payment, 300, 'refund-01', 'owner@example.test', 'reason');
        self::assertFalse($first['replayed']);
        self::assertSame(300, $payment->getRefundedAmount());
        self::assertSame('partially_refunded', $order->getPaymentState());
        self::assertSame('partially_refunded', $booking->getPaymentState());
        self::assertSame('owner@example.test', $first['operation']->getActor());
        self::assertSame('reason', $first['operation']->getReason());
        self::assertSame('AV-ORDER-42-'.strtoupper(substr(hash('sha256', 'refund-01'), 0, 8)), $first['operation']->getCreditNoteNumber());
        // Historical fast replay ignores a changed amount/reason/actor.
        $replay = $service->refund($payment, 999, 'refund-01', 'other', null);
        self::assertTrue($replay['replayed']);
        self::assertSame($first['operation'], $replay['operation']);
        $service->refund($payment, 700, 'refund-02', 'owner@example.test', null);
        self::assertSame(0, $payment->getRefundableAmount());
        self::assertSame('refunded', $order->getPaymentState());
        self::assertSame('refunded', $booking->getPaymentState());
        self::assertSame(Booking::STATUS_CANCELLED, $booking->getStatus());
        self::assertSame([[$payment, 300, 'refund-01'], [$payment, 700, 'refund-02']], $provider->calls);
    }

    public function testPendingKeyWithOtherAmountRollsBackWithoutProvider(): void
    {
        [$service, $payment, $order, , $provider, , $connection, $operations] = $this->fixture();
        $operations['refund-01'] = new RefundOperation($payment, $order, 'refund-01', 300, 'EUR', 'cash', 'owner', null);
        $connection->expects(self::once())->method('rollBack');
        try {
            $service->refund($payment, 400, 'refund-01', 'owner', null);
            self::fail('Expected conflict');
        } catch (\DomainException $e) {
            self::assertSame('Cette cle d’idempotence est deja utilisee pour une autre demande.', $e->getMessage());
        }
        self::assertSame([], $provider->calls);
    }

    public function testPendingReplayUsesOriginalKeyAndActorWithoutPersistingAnotherOperation(): void
    {
        [$service, $payment, $order, , $provider, $em, , $operations] = $this->fixture();
        $operation = new RefundOperation($payment, $order, 'refund-01', 300, 'EUR', 'cash', 'original', 'original reason');
        $operations['refund-01'] = $operation;
        $em->expects(self::never())->method('persist');
        $result = $service->refund($payment, 300, 'refund-01', 'second actor', null);
        self::assertFalse($result['replayed']);
        self::assertSame($operation, $result['operation']);
        self::assertSame('original', $operation->getActor());
        self::assertSame('original reason', $operation->getReason());
        self::assertSame([[$payment, 300, 'refund-01']], $provider->calls);
    }

    public function testCompletionObservedAfterLockIsReplayedWithoutProvider(): void
    {
        [$service, $payment, $order, , $provider, $em, $connection, $operations] = $this->fixture();
        $operation = new RefundOperation($payment, $order, 'refund-01', 300, 'EUR', 'cash', 'original', null);
        $em->method('refresh')->willReturnCallback(static function () use ($operations, $operation): void {
            $operation->complete('remote-existing', 'credit-existing');
            $operations['refund-01'] = $operation;
        });
        $connection->expects(self::once())->method('commit');
        $result = $service->refund($payment, 300, 'refund-01', 'second', null);
        self::assertTrue($result['replayed']);
        self::assertSame($operation, $result['operation']);
        self::assertSame([], $provider->calls);
    }

    public function testProviderFailureRollsBackWithoutRetryOrLocalPaymentMutation(): void
    {
        [$service, $payment, , , $provider, , $connection] = $this->fixture();
        $provider->failure = new \RuntimeException('remote timeout');
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        try {
            $service->refund($payment, 300, 'refund-01', 'owner', null);
            self::fail('Expected failure');
        } catch (RefundFailed $e) {
            self::assertSame($provider->failure, $e->getPrevious());
        }
        self::assertCount(1, $provider->calls);
        self::assertSame(0, $payment->getRefundedAmount());
    }

    public function testLocalFailureAfterRemoteSuccessDoesNotRetryAndLeavesManagedObjectsMutated(): void
    {
        [$service, $payment, , , $provider, $em, $connection] = $this->fixture();
        $flushes = 0;
        $em->method('flush')->willReturnCallback(static function () use (&$flushes): void {
            if (++$flushes === 2) throw new \RuntimeException('local write failed');
        });
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        try {
            $service->refund($payment, 300, 'refund-01', 'owner', null);
            self::fail('Expected failure');
        } catch (RefundFailed) {
            self::assertCount(1, $provider->calls);
            // A DB rollback does not restore Doctrine's managed PHP objects.
            self::assertSame(300, $payment->getRefundedAmount());
        }
    }
}
