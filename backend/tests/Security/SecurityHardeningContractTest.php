<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

final class SecurityHardeningContractTest extends TestCase
{
    public function testSensitiveEndpointsAreRateLimitedPerTenantAndClient(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Security/SensitiveEndpointRateLimiter.php');
        self::assertIsString($source);
        self::assertStringContainsString("['login', 5, 60]", $source);
        self::assertStringContainsString("['voucher', 30, 60]", $source);
        self::assertStringContainsString("tenantContext->getSlug()", $source);
        self::assertStringContainsString('HTTP_TOO_MANY_REQUESTS', $source);
    }

    public function testAdminImagesAreCheckedServerSide(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Security/ImageUploadValidator.php');
        self::assertIsString($source);
        self::assertStringContainsString('MAX_BYTES = 5_242_880', $source);
        self::assertStringContainsString("'image/jpeg'", $source);
        self::assertStringContainsString("'image/png'", $source);
        self::assertStringContainsString("'image/webp'", $source);
        self::assertStringContainsString('getMimeType()', $source);
        self::assertStringContainsString('basename($name)', $source);
    }

    public function testJwtLifetimeIsBounded(): void
    {
        $config = file_get_contents(__DIR__.'/../../config/packages/lexik_jwt_authentication.yaml');
        self::assertIsString($config);
        self::assertStringContainsString('token_ttl: 900', $config);
    }

    public function testSessionLoginsKeepCsrfAndCorsIsNotWildcarded(): void
    {
        $security = file_get_contents(__DIR__.'/../../config/packages/security.yaml');
        $headers = file_get_contents(__DIR__.'/../../src/Security/HttpSecurityHeadersSubscriber.php');
        self::assertIsString($security);
        self::assertIsString($headers);
        self::assertGreaterThanOrEqual(2, substr_count($security, 'enable_csrf: true'));
        self::assertStringNotContainsString("Access-Control-Allow-Origin', '*'", $headers);
        self::assertStringContainsString("X-Content-Type-Options', 'nosniff'", $headers);
    }
}
