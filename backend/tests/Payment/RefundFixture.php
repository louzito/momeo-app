<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Booking;
use App\Entity\Order\Order;
use App\Entity\Payment\Payment;
use App\Entity\Payment\RefundOperation;
use App\Service\Payment\RefundProvider;
use App\Service\Payment\RefundService;
use App\Service\Payment\RefundFailed;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;

trait RefundFixture
{
    protected function fixture(): array
    {
        $order = new Order();
        $order->setNumber('ORDER-42');
        $order->setCurrencyCode('EUR');
        $payment = new Payment();
        $payment->setAmount(1000);
        $payment->setState('completed');
        $order->addPayment($payment);
        $booking = new Booking();
        $operations = new \ArrayObject();
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback(static fn (array $criteria) => $operations[$criteria['idempotencyKey']] ?? null);
        $repository->method('findBy')->with(['payment' => $payment], ['id' => 'DESC'])->willReturnCallback(static fn () => array_reverse(array_values($operations->getArrayCopy())));
        $bookings = $this->createMock(EntityRepository::class);
        $bookings->method('findOneBy')->with(['orderNumber' => 'ORDER-42'])->willReturn($booking);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([[RefundOperation::class, $repository], [Booking::class, $bookings]]);
        $em->method('persist')->willReturnCallback(static function (RefundOperation $operation) use ($operations): void { $operations[$operation->getIdempotencyKey()] = $operation; });
        $connection = $this->createMock(Connection::class);
        $em->method('getConnection')->willReturn($connection);
        $provider = new class implements RefundProvider {
            public array $calls = [];
            public ?\Throwable $failure = null;
            public function refund(Payment $payment, int $amount, string $idempotencyKey): array
            {
                $this->calls[] = [$payment, $amount, $idempotencyKey];
                if ($this->failure) throw $this->failure;
                return ['provider' => 'fake', 'reference' => 'remote-42'];
            }
        };
        $workflow = $this->createMock(WorkflowInterface::class);
        $registry = new Registry();
        $registry->addWorkflow($workflow, new InstanceOfSupportStrategy(Payment::class));
        $workflow->method('getName')->willReturn('sylius_payment');
        return [new RefundService($em, $provider, $registry), $payment, $order, $booking, $provider, $em, $connection, $operations, $workflow];
    }

}
