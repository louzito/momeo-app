<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\TenantContext;
use App\Tenant\TenantRegistry;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['TODATEMPO_TENANT'], $_SERVER['SKYBOOK_TENANT']);
    }

    public function testCanonicalEnvironmentVariableHasPriority(): void
    {
        $_SERVER['TODATEMPO_TENANT'] = 'canonical';
        $_SERVER['SKYBOOK_TENANT'] = 'legacy';

        self::assertSame('canonical', $this->context()->getSlug());
    }

    public function testLegacyEnvironmentVariableRemainsReadable(): void
    {
        $_SERVER['SKYBOOK_TENANT'] = 'legacy';

        self::assertSame('legacy', $this->context()->getSlug());
    }

    public function testDefaultIsUsedWithoutEnvironmentVariable(): void
    {
        self::assertSame('default', $this->context()->getSlug());
    }

    private function context(): TenantContext
    {
        return new TenantContext(new TenantRegistry('/nonexistent/tenants.json', false), 'default');
    }
}
