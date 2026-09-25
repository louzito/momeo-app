<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use Doctrine\DBAL\Connection;

/** Resolves the registry before the first DBAL access, without reconnecting. */
final readonly class TenantDatabaseDiagnostic
{
    public function __construct(private TenantContext $context, private TenantRegistry $registry, private Connection $connection) {}

    /** @return array{expected: string, actual: mixed, matches: bool}|null */
    public function inspect(string $slug): ?array
    {
        $expected = $this->registry->databaseFor($slug);
        if ($expected === null) {
            return null;
        }
        $this->context->setSlug($slug);
        $actual = $this->connection->fetchOne('SELECT DATABASE()');

        return ['expected' => $expected, 'actual' => $actual, 'matches' => $actual === $expected];
    }
}
