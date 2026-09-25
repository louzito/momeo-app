<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TenantDatabaseDiagnoseCommand;
use App\Command\TenantListCommand;
use App\Command\TenantPoolAddCommand;
use App\Command\TenantRemoveCommand;
use App\Service\Tenant\CaddyConfigDumper;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantDatabaseCloner;
use App\Service\Tenant\TenantDatabaseDiagnostic;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantPoolManager;
use App\Service\Tenant\TenantRegistry;
use App\Service\Tenant\TenantRegistryWriter;
use App\Service\Tenant\TenantRemoval;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** Real services, temporary registry/Caddy output and a DBAL double: no database is mutated. */
final class TenantMaintenanceCommandTest extends TestCase
{
    private string $directory;
    private TenantRegistry $registry;
    private TenantRegistryWriter $writer;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tenant-maintenance-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory.'/tenants.json', json_encode([
            'template' => ['db' => 'template_db', 'status' => 'template'],
            'pool-002' => ['db' => 'pool_db', 'status' => 'pool'],
            'alpha' => ['db' => 'alpha`_db'],
        ], JSON_THROW_ON_ERROR));
        $this->registry = new TenantRegistry($this->directory.'/tenants.json', false);
        $this->writer = new TenantRegistryWriter($this->directory.'/tenants.json', new CaddyConfigDumper($this->registry, $this->directory, 'alpha', 'https://app.example.test'));
        $this->connection = $this->createMock(Connection::class);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testPoolCloneCompletesBeforeRegisteringTheNextSlug(): void
    {
        $this->connection->expects(self::once())->method('fetchFirstColumn')->with(self::stringContains('information_schema.TABLES'), ['template_db'])->willReturn(['sample']);
        $statements = [];
        $this->connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$statements): int {
            self::assertArrayNotHasKey('pool-003', $this->writer->read());
            $statements[] = $sql;
            return 0;
        });
        $command = new TenantPoolAddCommand(new TenantPoolManager($this->writer, new TenantDatabaseCloner($this->connection)));
        self::assertSame('todatempo:tenant:pool-add', $command->getName());
        self::assertContains('skybook:tenant:pool-add', $command->getAliases());
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));
        $entry = $this->writer->read()['pool-003'];
        self::assertMatchesRegularExpression('/^skybook_pool_[a-f0-9]{8}$/', $entry['db']);
        self::assertSame('pool', $entry['status']);
        self::assertSame('Centre pool-003', $entry['name']);
        self::assertTrue($entry['enabled']);
        self::assertCount(6, $statements);
        self::assertStringContainsString('`template_db`.`sample`', $statements[4]);
        self::assertSame('SET FOREIGN_KEY_CHECKS=1', $statements[5]);
        self::assertStringContainsString('+ pool-003 (db '.$entry['db'].', 1 tables clonees en ', $tester->getDisplay());
    }

    public function testMissingTemplateReturnsFailureWithoutAccessingTheDatabase(): void
    {
        $this->writer->remove('template');
        $this->connection->expects(self::never())->method('fetchFirstColumn');
        $this->connection->expects(self::never())->method('executeStatement');
        $tester = new CommandTester(new TenantPoolAddCommand(new TenantPoolManager($this->writer, new TenantDatabaseCloner($this->connection))));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('Aucune BDD template dans le registre.', $tester->getDisplay());
    }

    public function testCloneFailureDoesNotRegisterATenant(): void
    {
        $before = $this->writer->read();
        $this->connection->method('fetchFirstColumn')->willReturn(['sample']);
        $this->connection->method('executeStatement')->willThrowException(new \RuntimeException('clone failed'));
        try {
            (new TenantPoolManager($this->writer, new TenantDatabaseCloner($this->connection)))->add();
            self::fail('The clone failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('clone failed', $exception->getMessage());
        }
        self::assertSame($before, $this->writer->read());
    }

    public function testRemovalDropsBeforeRemovingAndKeepsCliOutputAndAliases(): void
    {
        $this->connection->expects(self::once())->method('executeStatement')->with('DROP DATABASE IF EXISTS `alpha_db`')->willReturnCallback(function (): int {
            self::assertArrayHasKey('alpha', $this->writer->read());
            return 0;
        });
        $command = new TenantRemoveCommand(new TenantRemoval($this->writer, $this->connection));
        self::assertSame('todatempo:tenant:remove', $command->getName());
        self::assertContains('skybook:tenant:remove', $command->getAliases());
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['slug' => 'alpha', '--drop-db' => true]));
        self::assertSame("BDD \"alpha`_db\" supprimee.\nTenant \"alpha\" retire du registre.\n", $tester->getDisplay());
        self::assertArrayNotHasKey('alpha', $this->writer->read());
        self::assertSame(1, $tester->execute(['slug' => 'alpha', '--drop-db' => true]));
        self::assertStringContainsString('Tenant "alpha" inconnu.', $tester->getDisplay());
    }

    public function testRegistryOnlyRemovalNeverDropsADatabase(): void
    {
        $this->connection->expects(self::never())->method('executeStatement');
        $tester = new CommandTester(new TenantRemoveCommand(new TenantRemoval($this->writer, $this->connection)));
        self::assertSame(0, $tester->execute(['slug' => 'alpha']));
        self::assertSame("Tenant \"alpha\" retire du registre.\n", $tester->getDisplay());
    }

    public function testFailedDropKeepsTheRegistryAndDoesNotReportSuccess(): void
    {
        $this->connection->method('executeStatement')->willThrowException(new \RuntimeException('drop failed'));
        $called = false;
        try {
            (new TenantRemoval($this->writer, $this->connection))->remove('alpha', true, static function () use (&$called): void { $called = true; });
            self::fail('The drop failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('drop failed', $exception->getMessage());
        }
        self::assertFalse($called);
        self::assertArrayHasKey('alpha', $this->writer->read());
    }

    public function testDatabaseDiagnosisResolvesBeforeQueryAndPreservesExitCodes(): void
    {
        $context = new TenantContext($this->registry, new TenantIdentifierResolver(), 'default');
        $context->setSlug('before');
        $this->connection->expects(self::exactly(2))->method('fetchOne')->with('SELECT DATABASE()')->willReturnCallback(static function () use ($context): string {
            self::assertContains($context->getSlug(), ['alpha', 'pool-002']);
            return 'alpha`_db';
        });
        $this->connection->expects(self::never())->method('close');
        $command = new TenantDatabaseDiagnoseCommand(new TenantDatabaseDiagnostic($context, $this->registry, $this->connection));
        self::assertSame('skybook:tenant:database-diagnose', $command->getName());
        $tester = new CommandTester($command);
        self::assertSame(2, $tester->execute(['tenant' => 'unknown']));
        self::assertSame('before', $context->getExplicitSlug());
        self::assertSame(0, $tester->execute(['tenant' => 'alpha']));
        self::assertStringContainsString('OK : tenant "alpha", base Doctrine "alpha`_db".', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['tenant' => 'pool-002']));
        self::assertStringContainsString('base attendue "pool_db"', $tester->getDisplay());
    }

    public function testListFiltersImplicitActiveStatusSortsAndPreservesCountPriority(): void
    {
        $command = new TenantListCommand($this->registry);
        self::assertSame('todatempo:tenant:list', $command->getName());
        self::assertContains('skybook:tenant:list', $command->getAliases());
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--status' => 'active', '--json' => true]));
        self::assertSame(['alpha' => ['db' => 'alpha`_db']], json_decode($tester->getDisplay(), true));
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['alpha', 'pool-002', 'template'], array_keys(json_decode($tester->getDisplay(), true)));
        self::assertSame(0, $tester->execute(['--status' => 'pool', '--count' => true, '--json' => true]));
        self::assertSame("1\n", $tester->getDisplay());
    }
}
