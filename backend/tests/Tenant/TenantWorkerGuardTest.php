<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Tenant\TenantContext;
use App\Tenant\TenantRegistry;
use App\Tenant\TenantWorkerGuard;
use PHPUnit\Framework\TestCase;

final class TenantWorkerGuardTest extends TestCase
{
    private string $registryFile;

    protected function setUp(): void
    {
        $this->registryFile = tempnam(sys_get_temp_dir(), 'tenants-');
        file_put_contents($this->registryFile, json_encode([
            'skyline' => ['db' => 'todatempo_skyline', 'status' => 'active'],
        ], \JSON_THROW_ON_ERROR));
        unset($_SERVER['SKYBOOK_TENANT'], $_ENV['SKYBOOK_TENANT']);
        putenv('SKYBOOK_TENANT');
    }

    protected function tearDown(): void
    {
        @unlink($this->registryFile);
        unset($_SERVER['SKYBOOK_TENANT'], $_ENV['SKYBOOK_TENANT']);
        putenv('SKYBOOK_TENANT');
    }

    public function testItRejectsAMissingExplicitTenantInsteadOfUsingTheDefault(): void
    {
        $guard = $this->guard();

        $this->expectExceptionMessage('Definissez SKYBOOK_TENANT');
        $guard->validate();
    }

    public function testItRejectsAnUnknownTenant(): void
    {
        $_SERVER['SKYBOOK_TENANT'] = 'unknown';

        $this->expectExceptionMessage('tenant "unknown" inconnu');
        $this->guard()->validate();
    }

    public function testItAcceptsSkylineAndKeepsItsRegisteredDatabase(): void
    {
        $_SERVER['SKYBOOK_TENANT'] = 'skyline';
        [$guard, $context] = $this->guardAndContext();

        self::assertSame('skyline', $guard->validate());
        self::assertSame('todatempo_skyline', $context->getDatabaseName());
    }

    private function guard(): TenantWorkerGuard
    {
        return $this->guardAndContext()[0];
    }

    /** @return array{TenantWorkerGuard, TenantContext} */
    private function guardAndContext(): array
    {
        $registry = new TenantRegistry($this->registryFile, false);
        $context = new TenantContext($registry, new \App\Tenant\TenantIdentifierResolver(), 'skyline');

        return [new TenantWorkerGuard($context, $registry), $context];
    }
}
