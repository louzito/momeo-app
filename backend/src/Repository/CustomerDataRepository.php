<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès SQL RGPD au schéma custom/Sylius du tenant courant, y compris tables optionnelles.
 * Les noms de tables/colonnes sont exclusivement fournis par CustomerDataManager.
 * Le service garde politiques, audit et transaction ; aucune connexion parallèle ici.
 */
final readonly class CustomerDataRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /** @return list<int> */
    public function orderAddressIds(int $customerId): array
    {
        $connection = $this->entityManager->getConnection();
        $addressIds = [];
        if ($this->hasColumn($connection, 'sylius_order', 'customer_id')) {
            foreach (['billing_address_id', 'shipping_address_id'] as $addressColumn) {
                if ($this->hasColumn($connection, 'sylius_order', $addressColumn)) {
                    $addressIds = array_merge($addressIds, array_map('intval', array_filter($connection->fetchFirstColumn(sprintf('SELECT %s FROM sylius_order WHERE customer_id = :id', $addressColumn), ['id' => $customerId]))));
                }
            }
        }
        return $addressIds;
    }

    public function clearOrderNotes(int $customerId): ?int
    {
        $connection = $this->entityManager->getConnection();
        if (!$this->hasColumn($connection, 'sylius_order', 'customer_id') || !$this->hasColumn($connection, 'sylius_order', 'notes')) return null;
        return $connection->executeStatement('UPDATE sylius_order SET notes = NULL WHERE customer_id = :id', ['id' => $customerId]);
    }

    /** @return array<string, int> */
    public function retentionCounts(string $bookingCutoff, string $waitlistCutoff): array
    {
        $connection = $this->entityManager->getConnection();
        return [
            'bookings' => $this->countWhere($connection, 'momeo_booking', "created_at < :cutoff AND customer_email NOT LIKE 'deleted+%@invalid.local'", ['cutoff' => $bookingCutoff]),
            'waitlistRequests' => $this->countWhere($connection, 'todatempo_waitlist_request', 'created_at < :cutoff', ['cutoff' => $waitlistCutoff]),
        ];
    }

    /** @param array<string, int> $counts @return array<string, int> */
    public function purgeExpired(string $bookingCutoff, string $waitlistCutoff, array $counts): array
    {
        $connection = $this->entityManager->getConnection();
        if ($this->hasTable($connection, 'momeo_booking')) {
            $counts['bookings'] = $connection->executeStatement("UPDATE momeo_booking SET customer_first_name = 'Supprime', customer_last_name = 'RGPD', customer_email = CONCAT('deleted+', SHA2(CONCAT(id, customer_email), 256), '@invalid.local'), customer_phone = NULL, customer_notes = NULL, sms_reminder_consent = 0, change_history = JSON_ARRAY() WHERE created_at < :cutoff AND customer_email NOT LIKE 'deleted+%@invalid.local'", ['cutoff' => $bookingCutoff]);
        }
        if ($this->hasTable($connection, 'todatempo_waitlist_request')) {
            $counts['waitlistRequests'] = $connection->executeStatement('DELETE FROM todatempo_waitlist_request WHERE created_at < :cutoff', ['cutoff' => $waitlistCutoff]);
        }
        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $table, string $column, string $email): array
    {
        $connection = $this->entityManager->getConnection();
        if (!$this->hasColumn($connection, $table, $column)) return [];
        $rows = $connection->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE LOWER(%s) = :email', $table, $column), ['email' => $email]);
        return array_map($this->sanitize(...), $rows);
    }

    public function delete(string $table, string $column, string $email): int
    {
        $connection = $this->entityManager->getConnection();
        if (!$this->hasColumn($connection, $table, $column)) return 0;
        return $connection->executeStatement(sprintf('DELETE FROM %s WHERE LOWER(%s) = :email', $table, $column), ['email' => $email]);
    }

    /** @return list<array<string, mixed>> */
    public function rowsBy(string $table, string $column, int|string $value): array
    {
        $connection = $this->entityManager->getConnection();
        if (!$this->hasColumn($connection, $table, $column)) return [];
        return array_map($this->sanitize(...), $connection->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE %s = :value', $table, $column), ['value' => $value]));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function sanitize(array $row): array
    {
        foreach (['password', 'password_reset_token', 'verify_token', 'public_token', 'unsubscribe_token', 'token_value'] as $key) unset($row[$key]);
        return $row;
    }

    /** @return list<int> */
    public function ids(string $table, string $column, string $email): array
    {
        $connection = $this->entityManager->getConnection();
        if (!$this->hasColumn($connection, $table, $column)) return [];
        return array_map('intval', $connection->fetchFirstColumn(sprintf('SELECT id FROM %s WHERE LOWER(%s) = :email', $table, $column), ['email' => $email]));
    }

    /** @param array<string, mixed> $values */
    public function anonymizeById(string $table, int $id, array $values): int
    {
        $connection = $this->entityManager->getConnection();
        if (!$this->hasColumn($connection, $table, 'id')) return 0;
        $columns = array_filter(array_keys($values), fn (string $candidate): bool => $this->hasColumn($connection, $table, $candidate));
        $parameters = ['id' => $id]; $sets = [];
        foreach ($columns as $candidate) { $sets[] = $candidate.' = :set_'.$candidate; $parameters['set_'.$candidate] = $values[$candidate]; }
        return $sets === [] ? 0 : $connection->executeStatement(sprintf('UPDATE %s SET %s WHERE id = :id', $table, implode(', ', $sets)), $parameters);
    }

    /** @param array<string, mixed> $values */
    public function anonymize(string $table, string $column, string $email, array $values): int
    {
        $connection = $this->entityManager->getConnection();
        if (!$this->hasColumn($connection, $table, $column)) return 0;
        $columns = array_filter(array_keys($values), fn (string $candidate): bool => $this->hasColumn($connection, $table, $candidate));
        if ($columns === []) return 0;
        $parameters = ['email' => $email]; $sets = [];
        foreach ($columns as $candidate) {
            $sets[] = $candidate.' = :set_'.$candidate;
            $value = $values[$candidate];
            $parameters['set_'.$candidate] = $value instanceof \Closure ? $value() : $value;
        }
        return $connection->executeStatement(sprintf('UPDATE %s SET %s WHERE LOWER(%s) = :email', $table, implode(', ', $sets), $column), $parameters);
    }

    /** @param array<string, mixed> $parameters */
    private function countWhere(Connection $connection, string $table, string $where, array $parameters): int
    {
        return $this->hasTable($connection, $table) ? (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where), $parameters) : 0;
    }

    private function hasTable(Connection $connection, string $table): bool { return $connection->createSchemaManager()->tablesExist([$table]); }
    private function hasColumn(Connection $connection, string $table, string $column): bool { return $this->hasTable($connection, $table) && $connection->createSchemaManager()->introspectTable($table)->hasColumn($column); }
}
