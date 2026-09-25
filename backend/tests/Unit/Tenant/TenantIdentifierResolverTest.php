<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Service\Tenant\TenantIdentifierResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TenantIdentifierResolverTest extends TestCase
{
    private TenantIdentifierResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TenantIdentifierResolver();
        $this->clearTenantEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearTenantEnvironment();
    }

    public function testNewHttpHeaderIsResolvedAndNormalized(): void
    {
        $request = new Request();
        $request->headers->set(TenantIdentifierResolver::HTTP_HEADER, '  Centre-Paris  ');

        self::assertSame('centre-paris', $this->resolver->fromRequest($request));
    }

    public function testLegacyHttpHeaderRemainsSupported(): void
    {
        $request = new Request();
        $request->headers->set(TenantIdentifierResolver::LEGACY_HTTP_HEADER, 'legacy-centre');

        self::assertSame('legacy-centre', $this->resolver->fromRequest($request));
    }

    public function testNewHttpHeaderWinsOnConflict(): void
    {
        $request = new Request();
        $request->headers->set(TenantIdentifierResolver::HTTP_HEADER, 'new-centre');
        $request->headers->set(TenantIdentifierResolver::LEGACY_HTTP_HEADER, 'legacy-centre');

        self::assertSame('new-centre', $this->resolver->fromRequest($request));
    }

    public function testNewEnvironmentVariableIsResolvedLikeHttpHeader(): void
    {
        $_SERVER[TenantIdentifierResolver::ENVIRONMENT_VARIABLE] = '  Centre-Paris  ';

        self::assertSame('centre-paris', $this->resolver->fromEnvironment());
    }

    public function testLegacyEnvironmentVariableRemainsSupported(): void
    {
        $_SERVER[TenantIdentifierResolver::LEGACY_ENVIRONMENT_VARIABLE] = 'legacy-centre';

        self::assertSame('legacy-centre', $this->resolver->fromEnvironment());
    }

    public function testNewEnvironmentVariableWinsOnConflict(): void
    {
        $_SERVER[TenantIdentifierResolver::ENVIRONMENT_VARIABLE] = 'new-centre';
        $_SERVER[TenantIdentifierResolver::LEGACY_ENVIRONMENT_VARIABLE] = 'legacy-centre';

        self::assertSame('new-centre', $this->resolver->fromEnvironment());
    }

    public function testSsoPostResolvesTheFormTenantBehindAPublicPrefix(): void
    {
        $request = Request::create('/todatempo-app/backend/api/v2/admin/todatempo/sso/handoff', 'POST', ['tenant' => '  Centre-Paris  '], [], [], [
            'SCRIPT_NAME' => '/todatempo-app/backend/index.php',
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
        ]);
        self::assertSame('centre-paris', $this->resolver->fromRequest($request));
    }

    public function testFormTenantCannotOverrideTheHeaderOrSelectOtherApiTenants(): void
    {
        $request = Request::create('/api/v2/admin/todatempo/sso/handoff', 'POST', ['tenant' => 'beta']);
        $request->headers->set(TenantIdentifierResolver::HTTP_HEADER, 'alpha');
        self::assertSame('alpha', $this->resolver->fromRequest($request));
        self::assertNull($this->resolver->fromRequest(Request::create('/api/v2/admin/orders', 'POST', ['tenant' => 'beta'])));
        self::assertNull($this->resolver->fromRequest(Request::create('/api/v2/admin/todatempo/sso/handoff', 'GET', ['tenant' => 'beta'])));
        self::assertNull($this->resolver->fromRequest(Request::create('/api/v2/admin/todatempo/sso/handoff', 'POST')));
    }

    private function clearTenantEnvironment(): void
    {
        $names = [
            TenantIdentifierResolver::ENVIRONMENT_VARIABLE,
            TenantIdentifierResolver::LEGACY_ENVIRONMENT_VARIABLE,
        ];
        foreach ($names as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }
    }
}
