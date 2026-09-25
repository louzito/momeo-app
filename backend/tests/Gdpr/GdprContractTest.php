<?php

declare(strict_types=1);

namespace App\Tests\Gdpr;

use App\Entity\GdprAuditLog;
use App\Repository\CustomerDataRepository;
use App\Service\Gdpr\CustomerDataManager;
use App\Service\Gdpr\RetentionPolicy;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** SQL exécuté uniquement dans SQLite :memory: ; aucune URL/connexion applicative. */
final class GdprContractTest extends TestCase
{
    private Connection $connection;
    private CustomerDataManager $manager;
    /** @var list<GdprAuditLog> */
    private array $audits = [];
    private bool $failAudit = false;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Equivalents de fonctions MySQL pour exercer le SQL de purge sur la fixture.
        $pdo = $this->connection->getNativeConnection();
        $pdo->sqliteCreateFunction('CONCAT', static fn (...$parts): string => implode('', $parts));
        $pdo->sqliteCreateFunction('SHA2', static fn (string $value, int $bits): string => hash('sha'.$bits, $value));
        $pdo->sqliteCreateFunction('JSON_ARRAY', static fn (): string => '[]');
        foreach ([
            'sylius_customer' => 'id INTEGER PRIMARY KEY, email TEXT, first_name TEXT, last_name TEXT, email_canonical TEXT, phone_number TEXT, birthday TEXT',
            'sylius_shop_user' => 'id INTEGER PRIMARY KEY, username TEXT, username_canonical TEXT, enabled INTEGER, password TEXT, password_reset_token TEXT, verified_at TEXT',
            'sylius_order' => 'id INTEGER PRIMARY KEY, customer_id INTEGER, number TEXT, billing_address_id INTEGER, shipping_address_id INTEGER, notes TEXT, token_value TEXT',
            'sylius_address' => 'id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, phone_number TEXT, company TEXT, street TEXT, city TEXT, postcode TEXT, province_name TEXT, country_code TEXT',
            'sylius_invoicing_invoice' => 'id INTEGER PRIMARY KEY, order_id INTEGER, order_number TEXT, total INTEGER, billing_data TEXT',
            'todatempo_client_profile' => 'id INTEGER PRIMARY KEY, booking_email TEXT, internal_notes TEXT',
            'momeo_booking' => 'id INTEGER PRIMARY KEY, customer_email TEXT, customer_first_name TEXT, customer_last_name TEXT, customer_phone TEXT, customer_notes TEXT, sms_reminder_consent INTEGER, public_token TEXT, change_history TEXT, created_at TEXT',
            'todatempo_waitlist_request' => 'id INTEGER PRIMARY KEY, customer_email TEXT, unsubscribe_token TEXT, created_at TEXT',
            'skybook_gift_voucher' => 'id INTEGER PRIMARY KEY, purchaser_email TEXT, beneficiary_email TEXT, purchaser_name TEXT, beneficiary_name TEXT, personal_message TEXT',
        ] as $table => $columns) {
            $this->connection->executeStatement("CREATE TABLE $table ($columns)");
        }
        $this->connection->insert('sylius_customer', ['id' => 1, 'email' => 'ALICE@example.test', 'first_name' => 'Alice']);
        $this->connection->insert('sylius_shop_user', ['id' => 1, 'username' => 'alice@example.test', 'password' => 'secret', 'enabled' => 1]);
        $this->connection->insert('sylius_order', ['id' => 1, 'customer_id' => 1, 'number' => 'ORDER-1', 'billing_address_id' => 1, 'shipping_address_id' => 1, 'notes' => 'private', 'token_value' => 'secret']);
        $this->connection->insert('sylius_address', ['id' => 1, 'first_name' => 'Alice', 'street' => 'Private street', 'country_code' => 'FR']);
        $this->connection->insert('sylius_invoicing_invoice', ['id' => 1, 'order_id' => 1, 'order_number' => 'ORDER-1', 'total' => 1500, 'billing_data' => 'accounting snapshot']);
        $this->connection->insert('todatempo_client_profile', ['booking_email' => 'alice@example.test', 'internal_notes' => 'private']);
        $this->connection->insert('skybook_gift_voucher', ['purchaser_email' => 'alice@example.test', 'beneficiary_email' => 'alice@example.test', 'personal_message' => 'private']);
        foreach ([1 => ['alice@example.test', '2020-01-01 00:00:00'], 2 => ['other@example.test', '2026-09-25 12:00:00'], 3 => ['boundary@example.test', '2023-09-25 12:00:00']] as $id => [$email, $created]) {
            $this->connection->insert('momeo_booking', ['id' => $id, 'customer_email' => $email, 'customer_first_name' => 'Alice', 'customer_notes' => 'private', 'sms_reminder_consent' => 1, 'public_token' => 'old-token', 'change_history' => '["private"]', 'created_at' => $created]);
        }
        $this->connection->insert('todatempo_waitlist_request', ['customer_email' => 'alice@example.test', 'unsubscribe_token' => 'secret', 'created_at' => '2020-01-01 00:00:00']);
        $this->connection->insert('todatempo_waitlist_request', ['customer_email' => 'boundary@example.test', 'created_at' => '2026-06-27 12:00:00']);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);
        $em->method('persist')->willReturnCallback(function (object $audit): void {
            self::assertInstanceOf(GdprAuditLog::class, $audit);
            $this->audits[] = $audit;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            if ($this->failAudit) throw new \RuntimeException('audit failure');
        });
        $context = new TenantContext(new TenantRegistry(__DIR__.'/../Fixtures/tenants.json', false), new TenantIdentifierResolver(), 'demo');
        $context->setSlug('demo');
        $this->manager = new CustomerDataManager($em, $context, new RetentionPolicy(36, 90, 10), new CustomerDataRepository($em));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testExportNormalizesSubjectIncludesAccountingAndRemovesSecrets(): void
    {
        $data = $this->manager->export(' ALICE@example.test ', 'customer');
        self::assertSame('alice@example.test', $data['subject']);
        self::assertSame('demo', $data['tenant']);
        self::assertSame(1, $data['formatVersion']);
        foreach (['profile', 'bookings', 'waitlistRequests', 'giftVouchersPurchased', 'giftVouchersReceived', 'customerAccount', 'orders'] as $key) self::assertCount(1, $data[$key]);
        // Les deux références d'adresse et les deux lookups facture restent inchangés.
        self::assertCount(2, $data['orderAddresses']);
        self::assertCount(2, $data['invoices']);
        self::assertSame('accounting snapshot', $data['invoices'][0]['billing_data']);
        foreach (['public_token', 'unsubscribe_token', 'token_value', 'password'] as $secret) self::assertStringNotContainsString('"'.$secret.'"', json_encode($data, JSON_THROW_ON_ERROR));
        $this->assertAudit('export', hash('sha256', 'demo|alice@example.test'));
    }

    public function testEraseIsRepeatablePreservesAccountingAndOtherCustomers(): void
    {
        $invoice = $this->connection->fetchAllAssociative('SELECT * FROM sylius_invoicing_invoice');
        $counts = $this->manager->erase(' ALICE@example.test ', 'customer');
        self::assertSame(['orders' => 1, 'orderAddresses' => 1, 'profiles' => 1, 'bookings' => 1, 'waitlistRequests' => 1, 'vouchersPurchased' => 1, 'vouchersReceived' => 1, 'customers' => 1, 'accounts' => 1], $counts);
        $booking = $this->connection->fetchAssociative('SELECT * FROM momeo_booking WHERE id = 1');
        self::assertSame('deleted+'.substr(hash('sha256', 'demo|alice@example.test'), 0, 24).'@invalid.local', $booking['customer_email']);
        self::assertNull($booking['customer_notes']);
        self::assertSame('[]', $booking['change_history']);
        self::assertSame(0, (int) $booking['sms_reminder_consent']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $booking['public_token']);
        self::assertSame('other@example.test', $this->connection->fetchOne('SELECT customer_email FROM momeo_booking WHERE id = 2'));
        self::assertNull($this->connection->fetchOne('SELECT notes FROM sylius_order'));
        self::assertSame('-', $this->connection->fetchOne('SELECT street FROM sylius_address'));
        self::assertSame('FR', $this->connection->fetchOne('SELECT country_code FROM sylius_address'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT enabled FROM sylius_shop_user'));
        self::assertNotSame('secret', $this->connection->fetchOne('SELECT password FROM sylius_shop_user'));
        self::assertSame($invoice, $this->connection->fetchAllAssociative('SELECT * FROM sylius_invoicing_invoice'));
        $this->assertAudit('erase', hash('sha256', 'demo|alice@example.test'));
        self::assertSame(0, array_sum($this->manager->erase('alice@example.test', 'customer')));
        self::assertSame($booking, $this->connection->fetchAssociative('SELECT * FROM momeo_booking WHERE id = 1'));
    }

    public function testAuditFailureRollsBackAllErasureWrites(): void
    {
        $before = $this->manager->export('alice@example.test', 'test');
        $this->failAudit = true;
        try {
            $this->manager->erase('alice@example.test', 'test');
            self::fail('Audit failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('audit failure', $exception->getMessage());
        }
        $this->failAudit = false;
        $after = $this->manager->export('alice@example.test', 'test');
        unset($before['generatedAt'], $after['generatedAt']);
        self::assertSame($before, $after);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT enabled FROM sylius_shop_user'));
    }

    public function testRetentionDryRunStrictBoundariesAndRepeatedPurge(): void
    {
        $now = new \DateTimeImmutable('2026-09-25T12:00:00Z');
        $expected = ['bookings' => 1, 'waitlistRequests' => 1];
        self::assertSame($expected, $this->manager->purge($now, 'test', true));
        self::assertSame([], $this->audits);
        self::assertSame('alice@example.test', $this->connection->fetchOne('SELECT customer_email FROM momeo_booking WHERE id = 1'));
        self::assertSame($expected, $this->manager->purge($now, 'test', false));
        $this->assertAudit('retention_purge', null);
        self::assertSame('old-token', $this->connection->fetchOne('SELECT public_token FROM momeo_booking WHERE id = 1'));
        self::assertSame('boundary@example.test', $this->connection->fetchOne('SELECT customer_email FROM momeo_booking WHERE id = 3'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM sylius_invoicing_invoice'));
        self::assertSame(['bookings' => 0, 'waitlistRequests' => 0], $this->manager->purge($now, 'test', false));
    }

    public function testMissingOptionalTablesAndColumnsAreTolerated(): void
    {
        $this->connection->executeStatement('DROP TABLE skybook_gift_voucher');
        $this->connection->executeStatement('DROP TABLE todatempo_client_profile');
        $this->connection->executeStatement('ALTER TABLE sylius_order DROP COLUMN notes');
        $data = $this->manager->export('alice@example.test', 'test');
        self::assertSame([], $data['giftVouchersPurchased']);
        self::assertSame([], $data['profile']);
        $counts = $this->manager->erase('alice@example.test', 'test');
        self::assertSame(0, $counts['profiles']);
        self::assertSame(0, $counts['vouchersPurchased']);
        self::assertArrayNotHasKey('orders', $counts);
        self::assertSame(1, $counts['bookings']);
    }

    public function testAuditFailureAlsoRollsBackRetentionPurge(): void
    {
        $this->failAudit = true;
        try {
            $this->manager->purge(new \DateTimeImmutable('2026-09-25T12:00:00Z'), 'test', false);
            self::fail('Audit failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('audit failure', $exception->getMessage());
        }
        self::assertSame('alice@example.test', $this->connection->fetchOne('SELECT customer_email FROM momeo_booking WHERE id = 1'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM todatempo_waitlist_request'));
    }

    public function testInvalidEmailDoesNotWriteAudit(): void
    {
        try {
            $this->manager->erase('invalid', 'test');
            self::fail('Invalid email must fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Adresse email invalide.', $exception->getMessage());
        }
        self::assertSame([], $this->audits);
    }

    private function assertAudit(string $action, ?string $subjectHash): void
    {
        $audit = $this->audits[array_key_last($this->audits)];
        self::assertSame($action, (new \ReflectionProperty($audit, 'action'))->getValue($audit));
        self::assertSame($subjectHash, (new \ReflectionProperty($audit, 'subjectHash'))->getValue($audit));
        self::assertStringNotContainsString('alice@example.test', json_encode(array_values((array) $audit), JSON_THROW_ON_ERROR));
    }
}
