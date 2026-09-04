<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Configuration\ProductionConfigurationValidator;
use PHPUnit\Framework\TestCase;

final class ProductionConfigurationValidatorTest extends TestCase
{
    private string $projectDir;

    /** @var array<string, string> */
    private array $validEnvironment;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/skybook-config-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config/jwt', 0777, true);
        mkdir($this->projectDir.'/config/encryption', 0777, true);
        file_put_contents($this->projectDir.'/config/jwt/private.pem', 'private');
        file_put_contents($this->projectDir.'/config/jwt/public.pem', 'public');
        file_put_contents($this->projectDir.'/config/encryption/payment.key', 'payment');
        file_put_contents($this->projectDir.'/wkhtmltopdf', "#!/bin/sh\nexit 0\n");
        chmod($this->projectDir.'/wkhtmltopdf', 0755);
        file_put_contents($this->projectDir.'/config/tenants.json', json_encode([
            'demo' => ['db' => 'app_demo', 'enabled' => true, 'status' => 'active'],
        ], \JSON_THROW_ON_ERROR));

        $this->validEnvironment = [
            'APP_SECRET' => 'secret',
            'DATABASE_URL' => 'mysql://app:password@db/app',
            'DEFAULT_URI' => 'https://example.test',
            'SKYBOOK_DEFAULT_TENANT' => 'demo',
            'MOMEO_PROVISIONING_SECRET' => 'provisioning-secret',
            'JWT_SECRET_KEY' => '%kernel.project_dir%/config/jwt/private.pem',
            'JWT_PUBLIC_KEY' => '%kernel.project_dir%/config/jwt/public.pem',
            'JWT_PASSPHRASE' => 'passphrase',
            'SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH' => '%kernel.project_dir%/config/encryption/payment.key',
            'WKHTMLTOPDF_PATH' => $this->projectDir.'/wkhtmltopdf',
            'MESSENGER_TRANSPORT_DSN' => 'doctrine://default',
            'SYLIUS_MESSENGER_TRANSPORT_MAIN_DSN' => 'doctrine://default',
            'SYLIUS_MESSENGER_TRANSPORT_MAIN_FAILED_DSN' => 'doctrine://default?queue_name=main_failed',
            'SYLIUS_MESSENGER_TRANSPORT_CATALOG_PROMOTION_REMOVAL_DSN' => 'doctrine://default?queue_name=catalog',
            'SYLIUS_MESSENGER_TRANSPORT_CATALOG_PROMOTION_REMOVAL_FAILED_DSN' => 'doctrine://default?queue_name=catalog_failed',
            'SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN' => 'sync://',
            'SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN' => 'sync://',
        ];

        foreach ($this->validEnvironment as $name => $value) {
            $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->validEnvironment) as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->projectDir);
    }

    public function testItAcceptsACompleteProductionConfiguration(): void
    {
        (new ProductionConfigurationValidator())->validate($this->projectDir);

        self::addToAssertionCount(1);
    }

    public function testItReportsEveryMissingRequiredValue(): void
    {
        $_SERVER['DATABASE_URL'] = '';
        $_SERVER['JWT_PASSPHRASE'] = '';
        $_SERVER['SYLIUS_MESSENGER_TRANSPORT_MAIN_DSN'] = '';

        try {
            (new ProductionConfigurationValidator())->validate($this->projectDir);
            self::fail('Une configuration incomplète aurait dû être rejetée.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('variable DATABASE_URL absente ou vide', $exception->getMessage());
            self::assertStringContainsString('variable JWT_PASSPHRASE absente ou vide', $exception->getMessage());
            self::assertStringContainsString('variable SYLIUS_MESSENGER_TRANSPORT_MAIN_DSN absente ou vide', $exception->getMessage());
        }
    }

    public function testItRejectsMissingKeysAndAnUnknownDefaultTenant(): void
    {
        $_SERVER['JWT_PUBLIC_KEY'] = '%kernel.project_dir%/config/jwt/missing.pem';
        $_SERVER['SKYBOOK_DEFAULT_TENANT'] = 'unknown';

        try {
            (new ProductionConfigurationValidator())->validate($this->projectDir);
            self::fail('Une configuration incohérente aurait dû être rejetée.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('JWT_PUBLIC_KEY ne pointe pas vers un fichier lisible et non vide', $exception->getMessage());
            self::assertStringContainsString('le tenant par défaut "unknown" est absent', $exception->getMessage());
        }
    }

    public function testItRejectsAnInvalidTenantRegistry(): void
    {
        file_put_contents($this->projectDir.'/config/tenants.json', '{invalid');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('registre des tenants invalide');

        (new ProductionConfigurationValidator())->validate($this->projectDir);
    }

    public function testItRejectsMissingPdfBinary(): void
    {
        $_SERVER['WKHTMLTOPDF_PATH'] = $this->projectDir.'/missing-wkhtmltopdf';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WKHTMLTOPDF_PATH ne pointe pas vers un binaire exécutable');

        (new ProductionConfigurationValidator())->validate($this->projectDir);
    }

    public function testItRejectsDebugModeInProduction(): void
    {
        $_SERVER['APP_DEBUG'] = '1';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('APP_DEBUG doit etre desactive en production');
            (new ProductionConfigurationValidator())->validate($this->projectDir);
        } finally {
            unset($_SERVER['APP_DEBUG']);
        }
    }
}
