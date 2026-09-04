<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Entity\GdprAuditLog;
use App\Tenant\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** Operations RGPD executees exclusivement dans la base du tenant courant. */
final readonly class CustomerDataManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private RetentionPolicy $policy,
    ) {}

    /** @return array<string, mixed> */
    public function export(string $email, string $actor): array
    {
        $email = $this->email($email);
        $connection = $this->entityManager->getConnection();
        $customers = $this->rows($connection, 'sylius_customer', 'email', $email);
        $orders = []; $addresses = []; $invoices = [];
        foreach ($customers as $customer) {
            if (!isset($customer['id'])) continue;
            $customerOrders = $this->rowsBy($connection, 'sylius_order', 'customer_id', (int) $customer['id']);
            $orders = array_merge($orders, $customerOrders);
            foreach ($customerOrders as $order) {
                foreach (['billing_address_id', 'shipping_address_id'] as $key) {
                    if (isset($order[$key])) $addresses = array_merge($addresses, $this->rowsBy($connection, 'sylius_address', 'id', (int) $order[$key]));
                }
                if (isset($order['id'])) $invoices = array_merge($invoices, $this->rowsBy($connection, 'sylius_invoicing_invoice', 'order_id', (int) $order['id']));
                if (isset($order['number'])) $invoices = array_merge($invoices, $this->rowsBy($connection, 'sylius_invoicing_invoice', 'order_number', (string) $order['number']));
            }
        }
        $data = [
            'formatVersion' => 1,
            'tenant' => $this->tenantContext->getSlug(),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'subject' => $email,
            'profile' => $this->rows($connection, 'todatempo_client_profile', 'booking_email', $email),
            'bookings' => $this->rows($connection, 'momeo_booking', 'customer_email', $email),
            'waitlistRequests' => $this->rows($connection, 'todatempo_waitlist_request', 'customer_email', $email),
            'giftVouchersPurchased' => $this->rows($connection, 'skybook_gift_voucher', 'purchaser_email', $email),
            'giftVouchersReceived' => $this->rows($connection, 'skybook_gift_voucher', 'beneficiary_email', $email),
            'customerAccount' => $customers,
            'orders' => $orders,
            'orderAddresses' => $addresses,
            'invoices' => $invoices,
        ];
        $this->audit('export', $email, $actor, ['datasets' => array_keys($data)]);

        return $data;
    }

    /** @return array<string, int> */
    public function erase(string $email, string $actor, string $reason = 'data_subject_request'): array
    {
        $email = $this->email($email);
        $connection = $this->entityManager->getConnection();
        $token = 'deleted+'.substr(hash('sha256', $this->tenantContext->getSlug().'|'.$email), 0, 24).'@invalid.local';
        $counts = [];

        $connection->transactional(function () use ($connection, $email, $token, $actor, $reason, &$counts): void {
            $customerIds = $this->ids($connection, 'sylius_customer', 'email', $email);
            $addressIds = [];
            foreach ($customerIds as $customerId) {
                if ($this->hasColumn($connection, 'sylius_order', 'customer_id')) {
                    foreach (['billing_address_id', 'shipping_address_id'] as $addressColumn) {
                        if ($this->hasColumn($connection, 'sylius_order', $addressColumn)) {
                            $addressIds = array_merge($addressIds, array_map('intval', array_filter($connection->fetchFirstColumn(sprintf('SELECT %s FROM sylius_order WHERE customer_id = :id', $addressColumn), ['id' => $customerId]))));
                        }
                    }
                    if ($this->hasColumn($connection, 'sylius_order', 'notes')) {
                        $counts['orders'] = ($counts['orders'] ?? 0) + $connection->executeStatement('UPDATE sylius_order SET notes = NULL WHERE customer_id = :id', ['id' => $customerId]);
                    }
                }
            }
            foreach (array_unique($addressIds) as $addressId) {
                $counts['orderAddresses'] = ($counts['orderAddresses'] ?? 0) + $this->anonymizeById($connection, 'sylius_address', $addressId, ['first_name' => 'Supprime', 'last_name' => 'RGPD', 'phone_number' => null, 'company' => null, 'street' => '-', 'city' => '-', 'postcode' => '-', 'province_name' => null]);
            }
            $counts['profiles'] = $this->delete($connection, 'todatempo_client_profile', 'booking_email', $email);
            $counts['bookings'] = $this->anonymize($connection, 'momeo_booking', 'customer_email', $email, [
                'customer_first_name' => 'Supprime', 'customer_last_name' => 'RGPD', 'customer_email' => $token,
                'customer_phone' => null, 'customer_notes' => null, 'sms_reminder_consent' => false,
                'public_token' => fn (): string => bin2hex(random_bytes(16)), 'change_history' => '[]',
            ]);
            $counts['waitlistRequests'] = $this->delete($connection, 'todatempo_waitlist_request', 'customer_email', $email);
            $counts['vouchersPurchased'] = $this->anonymize($connection, 'skybook_gift_voucher', 'purchaser_email', $email, ['purchaser_name' => 'Supprime RGPD', 'purchaser_email' => $token, 'personal_message' => null]);
            $counts['vouchersReceived'] = $this->anonymize($connection, 'skybook_gift_voucher', 'beneficiary_email', $email, ['beneficiary_name' => 'Supprime RGPD', 'beneficiary_email' => $token, 'personal_message' => null]);
            $counts['customers'] = $this->anonymize($connection, 'sylius_customer', 'email', $email, ['first_name' => 'Supprime', 'last_name' => 'RGPD', 'email' => $token, 'email_canonical' => $token, 'phone_number' => null, 'birthday' => null]);
            $counts['accounts'] = $this->anonymize($connection, 'sylius_shop_user', 'username', $email, ['username' => $token, 'username_canonical' => $token, 'enabled' => false, 'password' => bin2hex(random_bytes(32)), 'password_reset_token' => null, 'verified_at' => null]);
            $this->persistAudit('erase', $email, $actor, ['reason' => $reason, 'counts' => $counts]);
        });
        $this->entityManager->clear();

        return $counts;
    }

    /** @return array<string, int> */
    public function purge(\DateTimeImmutable $now, string $actor, bool $dryRun): array
    {
        $connection = $this->entityManager->getConnection();
        $bookingCutoff = $this->policy->bookingCutoff($now)->format('Y-m-d H:i:s');
        $waitlistCutoff = $this->policy->waitlistCutoff($now)->format('Y-m-d H:i:s');
        $counts = [
            'bookings' => $this->countWhere($connection, 'momeo_booking', "created_at < :cutoff AND customer_email NOT LIKE 'deleted+%@invalid.local'", ['cutoff' => $bookingCutoff]),
            'waitlistRequests' => $this->countWhere($connection, 'todatempo_waitlist_request', 'created_at < :cutoff', ['cutoff' => $waitlistCutoff]),
        ];
        if ($dryRun) {
            return $counts;
        }

        $connection->transactional(function () use ($connection, $bookingCutoff, $waitlistCutoff, $actor, &$counts): void {
            if ($this->hasTable($connection, 'momeo_booking')) {
                $counts['bookings'] = $connection->executeStatement("UPDATE momeo_booking SET customer_first_name = 'Supprime', customer_last_name = 'RGPD', customer_email = CONCAT('deleted+', SHA2(CONCAT(id, customer_email), 256), '@invalid.local'), customer_phone = NULL, customer_notes = NULL, sms_reminder_consent = 0, change_history = JSON_ARRAY() WHERE created_at < :cutoff AND customer_email NOT LIKE 'deleted+%@invalid.local'", ['cutoff' => $bookingCutoff]);
            }
            if ($this->hasTable($connection, 'todatempo_waitlist_request')) {
                $counts['waitlistRequests'] = $connection->executeStatement('DELETE FROM todatempo_waitlist_request WHERE created_at < :cutoff', ['cutoff' => $waitlistCutoff]);
            }
            $this->persistAudit('retention_purge', null, $actor, ['counts' => $counts, 'policy' => $this->policy->describe()]);
        });
        $this->entityManager->clear();

        return $counts;
    }

    private function audit(string $action, string $email, string $actor, array $details): void
    {
        $this->persistAudit($action, $email, $actor, $details);
        $this->entityManager->flush();
    }

    private function persistAudit(string $action, ?string $email, string $actor, array $details): void
    {
        $this->entityManager->persist(new GdprAuditLog(bin2hex(random_bytes(32)), $action, $email === null ? null : hash('sha256', $this->tenantContext->getSlug().'|'.$email), mb_substr($actor, 0, 180), $details));
        $this->entityManager->flush();
    }

    private function email(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Adresse email invalide.');
        return $email;
    }

    /** @return list<array<string, mixed>> */
    private function rows(Connection $connection, string $table, string $column, string $email): array
    {
        if (!$this->hasColumn($connection, $table, $column)) return [];
        $rows = $connection->fetchAllAssociative(sprintf('SELECT * FROM %s WHERE LOWER(%s) = :email', $table, $column), ['email' => $email]);
        return array_map($this->sanitize(...), $rows);
    }

    private function delete(Connection $connection, string $table, string $column, string $email): int
    {
        if (!$this->hasColumn($connection, $table, $column)) return 0;
        return $connection->executeStatement(sprintf('DELETE FROM %s WHERE LOWER(%s) = :email', $table, $column), ['email' => $email]);
    }

    /** @return list<array<string, mixed>> */
    private function rowsBy(Connection $connection, string $table, string $column, int|string $value): array
    {
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
    private function ids(Connection $connection, string $table, string $column, string $email): array
    {
        if (!$this->hasColumn($connection, $table, $column)) return [];
        return array_map('intval', $connection->fetchFirstColumn(sprintf('SELECT id FROM %s WHERE LOWER(%s) = :email', $table, $column), ['email' => $email]));
    }

    /** @param array<string, mixed> $values */
    private function anonymizeById(Connection $connection, string $table, int $id, array $values): int
    {
        if (!$this->hasColumn($connection, $table, 'id')) return 0;
        $columns = array_filter(array_keys($values), fn (string $candidate): bool => $this->hasColumn($connection, $table, $candidate));
        $parameters = ['id' => $id]; $sets = [];
        foreach ($columns as $candidate) { $sets[] = $candidate.' = :set_'.$candidate; $parameters['set_'.$candidate] = $values[$candidate]; }
        return $sets === [] ? 0 : $connection->executeStatement(sprintf('UPDATE %s SET %s WHERE id = :id', $table, implode(', ', $sets)), $parameters);
    }

    /** @param array<string, mixed> $values */
    private function anonymize(Connection $connection, string $table, string $column, string $email, array $values): int
    {
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
