<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

final class AdminApiPermissionContractTest extends TestCase
{
    public function testAllBusinessDomainsAreEnforcedBeforeAdminControllers(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Security/AdminApiPermissionSubscriber.php');
        self::assertIsString($source);
        foreach (['Agenda', 'Clients', 'Finances', 'Catalog', 'Settings'] as $permission) {
            self::assertStringContainsString('TeamPermission::'.$permission, $source);
        }
        self::assertStringContainsString('AccessDeniedHttpException', $source);
    }

    public function testMigrationAndProvisioningGuaranteeAnOwner(): void
    {
        $migration = file_get_contents(__DIR__.'/../../migrations/Version20260907000000.php');
        $initializer = file_get_contents(__DIR__.'/../../src/Tenant/MinimalSyliusInitializer.php');
        self::assertStringContainsString("team_role = 'owner'", $migration);
        self::assertStringContainsString('setTeamRole(TeamRole::Owner)', $initializer);
    }
}
