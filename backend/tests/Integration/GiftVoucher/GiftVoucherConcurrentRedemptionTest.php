<?php

declare(strict_types=1);

namespace App\Tests\Integration\GiftVoucher;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Preuve, avec de vrais verrous InnoDB (comme BookingSlotConcurrencyTest pour
 * les creneaux), que deux tentatives simultanees de consommer le meme cheque
 * cadeau ne peuvent pas toutes les deux reussir : la seconde doit attendre le
 * verrou pessimiste pose par la premiere (findOneByCodeForUpdate), puis se
 * voir refusee car le statut n'est plus `active`.
 */
final class GiftVoucherConcurrentRedemptionTest extends KernelTestCase
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

    public function testOnlyOneConcurrentRedemptionSucceeds(): void
    {
        $code = (string) random_int(1_000_000_000, 1_999_999_999);
        $this->insertActiveVoucher($this->connection, $code);
        $process = null;

        try {
            $this->connection->beginTransaction();
            $this->connection->executeQuery('SELECT status FROM skybook_gift_voucher WHERE code = ? FOR UPDATE', [$code])->fetchOne();

            $process = $this->startCompetitor($code);
            usleep(200_000);
            self::assertTrue(proc_get_status($process['resource'])['running'], 'The competing redemption must wait on the row lock held by the first one.');

            $this->connection->executeStatement('UPDATE skybook_gift_voucher SET status = ?, used_at = NOW(), usage_order_number = ? WHERE code = ?', ['used', 'BOOK-FIRST', $code]);
            $this->connection->commit();

            $stdout = trim((string) stream_get_contents($process['pipes'][1]));
            $stderr = (string) stream_get_contents($process['pipes'][2]);
            $exitCode = proc_close($process['resource']);
            $process = null;

            self::assertSame(0, $exitCode, $stderr);
            self::assertSame('already_used', $stdout, 'The second redemption must see the committed `used` status and refuse itself.');
            self::assertSame('used', $this->connection->fetchOne('SELECT status FROM skybook_gift_voucher WHERE code = ?', [$code]));
            self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM skybook_gift_voucher WHERE code = ? AND status = 'used'", [$code]));
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if ($process !== null) {
                proc_terminate($process['resource']);
                proc_close($process['resource']);
            }
            $this->connection->executeStatement('DELETE FROM skybook_gift_voucher WHERE code = ?', [$code]);
        }
    }

    /** @return array{resource: resource, pipes: array<int, resource>} */
    private function startCompetitor(string $code): array
    {
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$params = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
$connection = \Doctrine\DBAL\DriverManager::getConnection($params);
$code = $argv[3];
$connection->beginTransaction();
try {
    $status = $connection->executeQuery('SELECT status FROM skybook_gift_voucher WHERE code = ? FOR UPDATE', [$code])->fetchOne();
    if ($status !== 'active') {
        echo 'already_used';
    } else {
        $connection->executeStatement('UPDATE skybook_gift_voucher SET status = ?, used_at = NOW(), usage_order_number = ? WHERE code = ?', ['used', 'BOOK-SECOND', $code]);
        echo 'redeemed';
    }
    $connection->commit();
} catch (\Throwable $e) {
    $connection->rollBack();
    echo 'error:'.$e->getMessage();
}
PHP;
        $command = [PHP_BINARY, '-r', $script, dirname(__DIR__, 3), base64_encode(serialize($this->connection->getParams())), $code];
        $pipes = [];
        $resource = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($resource);

        return ['resource' => $resource, 'pipes' => $pipes];
    }

    private function insertActiveVoucher(Connection $connection, string $code): void
    {
        $connection->insert('skybook_gift_voucher', [
            'code' => $code, 'status' => 'active', 'jump_type_code' => 'test', 'jump_type_name' => 'Test',
            'amount' => 9900, 'currency_code' => 'EUR', 'purchaser_name' => 'Test Purchaser',
            'purchaser_email' => 'purchaser@example.test', 'beneficiary_email' => 'beneficiary@example.test',
            'purchase_order_number' => 'ORDER-'.$code, 'expires_at' => '2035-01-01 00:00:00',
            'created_at' => '2030-01-01 00:00:00', 'activated_at' => '2030-01-01 00:00:00',
        ]);
    }
}
