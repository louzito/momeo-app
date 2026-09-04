<?php

declare(strict_types=1);

namespace App\Tests\Gdpr;

use PHPUnit\Framework\TestCase;

final class GdprContractTest extends TestCase
{
    public function testOperationsStayTenantScopedIdempotentAndAudited(): void
    {
        $manager = file_get_contents(__DIR__.'/../../src/Gdpr/CustomerDataManager.php');
        $command = file_get_contents(__DIR__.'/../../src/Command/GdprPurgeCommand.php');
        self::assertStringContainsString('TenantContext', $manager);
        self::assertStringContainsString("customer_email NOT LIKE 'deleted+%@invalid.local'", $manager);
        self::assertStringContainsString("persistAudit('retention_purge'", $manager);
        self::assertStringContainsString('TenantWorkerGuard', $command);
        self::assertStringContainsString("addOption('dry-run'", $command);
    }

    public function testInvoicesAreNotDeletedAndLegalQuestionsAreDocumented(): void
    {
        $manager = file_get_contents(__DIR__.'/../../src/Gdpr/CustomerDataManager.php');
        self::assertStringNotContainsString('DELETE FROM sylius_invoicing', $manager);
        $documentation = file_get_contents(__DIR__.'/../../docs/rgpd-retention.md');
        self::assertStringContainsString('Validation juridique requise', $documentation);
        self::assertStringContainsString('factures ne sont jamais purgées', $documentation);
    }
}
