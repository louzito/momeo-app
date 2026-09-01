<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\DBAL\Connection;

/**
 * Clone une base MySQL (schema + donnees) en SQL pur, sans mysqldump (absent
 * de l'image php). Utilise pour fabriquer les BDD du pool a partir de la BDD
 * template (deja migree + sylius:install minimal + provision SkyBook) :
 * ~quelques secondes au lieu de plusieurs minutes d'installation.
 */
final class TenantDatabaseCloner
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return int nombre de tables copiees */
    public function cloneDatabase(string $sourceDb, string $targetDb): int
    {
        $q = static fn (string $id): string => '`' . str_replace('`', '', $id) . '`';
        $tables = $this->connection->fetchFirstColumn(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            [$sourceDb],
        );
        if ($tables === []) {
            throw new \RuntimeException(sprintf('Base source "%s" absente ou vide.', $sourceDb));
        }
        $this->connection->executeStatement(sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $q($targetDb),
        ));
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                $src = $q($sourceDb) . '.' . $q((string) $table);
                $dst = $q($targetDb) . '.' . $q((string) $table);
                $this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $dst));
                $this->connection->executeStatement(sprintf('CREATE TABLE %s LIKE %s', $dst, $src));
                $this->connection->executeStatement(sprintf('INSERT INTO %s SELECT * FROM %s', $dst, $src));
            }
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }

        return \count($tables);
    }
}
