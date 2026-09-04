<?php

declare(strict_types=1);

namespace App\Tests\Integration\Booking;

use App\Booking\BookingSlotGuard;
use App\Entity\Booking;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookingSlotConcurrencyTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        if ($this->connection->getDatabasePlatform()->getName() !== 'mysql') {
            self::markTestSkipped('Row-lock concurrency is an InnoDB integration test.');
        }
    }

    public function testTwoTransactionsCannotConsumeTheLastPlanningPlace(): void
    {
        $planningCode = 'concurrency_'.bin2hex(random_bytes(8));
        $start = new \DateTimeImmutable('2035-05-10T09:00:00+00:00');
        $end = $start->modify('+1 hour');
        $guard = new BookingSlotGuard($this->connection);
        $process = null;

        try {
            $this->connection->beginTransaction();
            $guard->assertAvailable($this->candidate($planningCode, $start, $end), 1);
            $this->insertBooking($this->connection, $planningCode, $start, $end, 'parent');

            $process = $this->startCompetitor($planningCode, $start, $end);
            usleep(200_000);
            self::assertTrue(proc_get_status($process['resource'])['running'], 'The competing transaction must wait on the row lock.');
            $this->connection->commit();

            $stdout = stream_get_contents($process['pipes'][1]);
            $stderr = stream_get_contents($process['pipes'][2]);
            $exitCode = proc_close($process['resource']);
            $process = null;

            self::assertSame(0, $exitCode, $stderr);
            self::assertSame('slot_unavailable', trim($stdout));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM momeo_booking WHERE planning_code = ?', [$planningCode]));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($process !== null) {
                proc_terminate($process['resource']);
                proc_close($process['resource']);
            }
            $this->connection->executeStatement('DELETE FROM momeo_booking WHERE planning_code = ?', [$planningCode]);
            $this->connection->executeStatement('DELETE FROM momeo_booking_lock WHERE lock_key = ?', ['planning:'.$planningCode]);
        }
    }

    /** @return array{resource: resource, pipes: array<int, resource>} */
    private function startCompetitor(string $planningCode, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$params = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$booking = new \App\Entity\Booking();
$booking->setPlanningCode($argv[3]);
$booking->setSlotStart(new \DateTimeImmutable($argv[4]));
$booking->setSlotEnd(new \DateTimeImmutable($argv[5]));
$connection->beginTransaction();
try {
    (new \App\Booking\BookingSlotGuard($connection))->assertAvailable($booking, 1);
    $connection->commit();
    echo 'created';
} catch (\App\Booking\SlotUnavailable) {
    $connection->rollBack();
    echo 'slot_unavailable';
}
PHP;
        $command = [PHP_BINARY, '-r', $script, dirname(__DIR__, 3), base64_encode(serialize($this->connection->getParams())), $planningCode, $start->format(\DateTimeInterface::ATOM), $end->format(\DateTimeInterface::ATOM)];
        $pipes = [];
        $resource = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($resource);

        return ['resource' => $resource, 'pipes' => $pipes];
    }

    private function candidate(string $planningCode, \DateTimeImmutable $start, \DateTimeImmutable $end): Booking
    {
        $booking = new Booking();
        $booking->setPlanningCode($planningCode);
        $booking->setSlotStart($start);
        $booking->setSlotEnd($end);

        return $booking;
    }

    private function insertBooking(Connection $connection, string $planningCode, \DateTimeImmutable $start, \DateTimeImmutable $end, string $suffix): void
    {
        $connection->insert('momeo_booking', [
            'reference' => 'TEST-'.bin2hex(random_bytes(5)), 'public_token' => bin2hex(random_bytes(16)),
            'status' => Booking::STATUS_CONFIRMED, 'source' => 'test', 'service_code' => 'test', 'service_name' => 'Test',
            'planning_code' => $planningCode, 'customer_first_name' => 'Test', 'customer_last_name' => $suffix,
            'customer_email' => $suffix.'@example.test', 'slot_start' => $start->format('Y-m-d H:i:s'),
            'slot_end' => $end->format('Y-m-d H:i:s'), 'options' => '[]', 'currency_code' => 'EUR',
            'created_at' => '2035-01-01 00:00:00', 'updated_at' => '2035-01-01 00:00:00',
        ]);
    }
}
