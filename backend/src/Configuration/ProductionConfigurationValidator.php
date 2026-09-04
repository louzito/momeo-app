<?php

declare(strict_types=1);

namespace App\Configuration;

/** Prevents a production process from starting with an implicit or partial setup. */
final class ProductionConfigurationValidator
{
    private const REQUIRED_VALUES = [
        'APP_SECRET',
        'DATABASE_URL',
        'DEFAULT_URI',
        'SKYBOOK_DEFAULT_TENANT',
        'MOMEO_PROVISIONING_SECRET',
        'JWT_SECRET_KEY',
        'JWT_PUBLIC_KEY',
        'JWT_PASSPHRASE',
        'SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH',
        'WKHTMLTOPDF_PATH',
        'MESSENGER_TRANSPORT_DSN',
        'SYLIUS_MESSENGER_TRANSPORT_MAIN_DSN',
        'SYLIUS_MESSENGER_TRANSPORT_MAIN_FAILED_DSN',
        'SYLIUS_MESSENGER_TRANSPORT_CATALOG_PROMOTION_REMOVAL_DSN',
        'SYLIUS_MESSENGER_TRANSPORT_CATALOG_PROMOTION_REMOVAL_FAILED_DSN',
        'SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN',
        'SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN',
    ];

    public function validate(string $projectDir): void
    {
        $errors = [];
        $values = [];

        foreach (self::REQUIRED_VALUES as $name) {
            $values[$name] = $this->environmentValue($name);
            if ($values[$name] === null || trim($values[$name]) === '') {
                $errors[] = sprintf('variable %s absente ou vide', $name);
            }
        }

        $debug = strtolower(trim($this->environmentValue('APP_DEBUG') ?? '0'));
        if (\in_array($debug, ['1', 'true', 'yes', 'on'], true)) {
            $errors[] = 'APP_DEBUG doit etre desactive en production';
        }

        foreach (['JWT_SECRET_KEY', 'JWT_PUBLIC_KEY', 'SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH'] as $name) {
            $configuredPath = $values[$name] ?? '';
            if ($configuredPath === '') {
                continue;
            }

            $path = str_replace('%kernel.project_dir%', $projectDir, $configuredPath);
            if (!is_file($path) || !is_readable($path) || (int) filesize($path) <= 0) {
                $errors[] = sprintf('%s ne pointe pas vers un fichier lisible et non vide (%s)', $name, $path);
            }
        }

        $pdfBinary = $values['WKHTMLTOPDF_PATH'] ?? '';
        if ($pdfBinary !== '' && (!is_file($pdfBinary) || !is_executable($pdfBinary))) {
            $errors[] = sprintf('WKHTMLTOPDF_PATH ne pointe pas vers un binaire exécutable (%s)', $pdfBinary);
        }

        $registryFile = $projectDir.'/config/tenants.json';
        $tenants = $this->readRegistry($registryFile, $errors);
        $defaultTenant = $values['SKYBOOK_DEFAULT_TENANT'] ?? '';
        if ($tenants !== null && $defaultTenant !== '' && !isset($tenants[$defaultTenant])) {
            $errors[] = sprintf('le tenant par défaut "%s" est absent de %s', $defaultTenant, $registryFile);
        }

        if ($errors !== []) {
            throw new \RuntimeException("Configuration de production invalide :\n - ".implode("\n - ", $errors));
        }
    }

    private function environmentValue(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }

    /** @param list<string> $errors
     *  @return array<string, array<string, mixed>>|null
     */
    private function readRegistry(string $file, array &$errors): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            $errors[] = sprintf('registre des tenants absent ou illisible (%s)', $file);

            return null;
        }

        try {
            $tenants = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $errors[] = sprintf('registre des tenants invalide (%s) : %s', $file, $exception->getMessage());

            return null;
        }

        if (!is_array($tenants) || $tenants === []) {
            $errors[] = sprintf('registre des tenants vide ou mal formé (%s)', $file);

            return null;
        }

        foreach ($tenants as $slug => $tenant) {
            if (!is_string($slug) || $slug === '' || !is_array($tenant) || !is_string($tenant['db'] ?? null) || trim($tenant['db']) === '') {
                $errors[] = sprintf('entrée de tenant invalide dans %s (slug et db sont requis)', $file);
            }
        }

        /** @var array<string, array<string, mixed>> $tenants */
        return $tenants;
    }
}
