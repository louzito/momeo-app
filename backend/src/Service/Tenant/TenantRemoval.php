<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use Doctrine\DBAL\Connection;

/** Optional database removal must succeed before the registry entry is removed. */
final readonly class TenantRemoval
{
    public function __construct(private TenantRegistryWriter $writer, private Connection $connection) {}

    /**
     * @param callable(string): void $databaseRemoved Progress notification before registry removal,
     *        so the CLI still reports a completed DROP even if the subsequent write fails.
     */
    public function remove(string $slug, bool $dropDatabase, callable $databaseRemoved): bool
    {
        $entry = $this->writer->read()[$slug] ?? null;
        if ($entry === null) {
            return false;
        }
        if ($dropDatabase && \is_string($entry['db'] ?? null) && $entry['db'] !== '') {
            $this->connection->executeStatement('DROP DATABASE IF EXISTS `' . str_replace('`', '', $entry['db']) . '`');
            $databaseRemoved($entry['db']);
        }
        $this->writer->remove($slug);

        return true;
    }
}
