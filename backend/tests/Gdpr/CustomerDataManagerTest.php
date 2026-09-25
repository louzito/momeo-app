<?php

declare(strict_types=1);

namespace App\Tests\Gdpr;

use App\Service\Gdpr\CustomerDataManager;
use App\Service\Gdpr\RetentionPolicy;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CustomerDataManagerTest extends TestCase
{
    public function testDryRunCountsExpiredDataWithoutWritingOrAuditing(): void
    {
        $connection = $this->createMock(Connection::class);
        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(true);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->expects(self::exactly(2))->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $parameters): int {
                if (str_contains($sql, 'momeo_booking')) {
                    self::assertSame(['cutoff' => '2023-09-25 12:00:00'], $parameters);
                    self::assertStringContainsString("customer_email NOT LIKE 'deleted+%@invalid.local'", $sql);
                    return 3;
                }
                self::assertStringContainsString('todatempo_waitlist_request', $sql);
                self::assertSame(['cutoff' => '2026-06-27 12:00:00'], $parameters);
                return 4;
            },
        );
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('transactional');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $registry = new TenantRegistry(__DIR__.'/../Fixtures/tenants.json', false);
        $context = new TenantContext($registry, new TenantIdentifierResolver(), 'demo');
        $context->setSlug('demo');
        $manager = new CustomerDataManager($em, $context, new RetentionPolicy(36, 90, 10));
        self::assertSame(['bookings' => 3, 'waitlistRequests' => 4], $manager->purge(new \DateTimeImmutable('2026-09-25T12:00:00Z'), 'unit-test', true));
    }
}
