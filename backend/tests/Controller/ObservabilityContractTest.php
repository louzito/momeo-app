<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ObservabilityController;
use App\Service\Observability\HealthChecker;
use App\Service\Observability\MetricsRegistry;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ObservabilityContractTest extends TestCase
{
    public function testHealthChecksAreDistinctAndResponsesDoNotExposeConfiguration(): void
    {
        $registry = new TenantRegistry(__DIR__.'/../Fixtures/tenants.json', false);
        $context = new TenantContext($registry, new TenantIdentifierResolver(), 'demo');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('close');
        $connection->expects(self::once())->method('executeQuery')->with('SELECT 1')->willReturnCallback(static function () use ($context): never {
            self::assertSame('demo', $context->getSlug());
            throw new \RuntimeException('secret database credentials');
        });
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->with('GET', 'https://internal.example.test', ['timeout' => 2.0])->willThrowException(new \RuntimeException('private dependency'));
        $controller = new ObservabilityController(new HealthChecker($registry, $context, $connection, $http, 'https://internal.example.test'), new MetricsRegistry('/nonexistent/metrics.json'));
        self::assertSame('{"status":"ok"}', $controller->live()->getContent());
        self::assertSame(200, $controller->ready()->getStatusCode());
        self::assertSame(503, $controller->tenantLive('unknown')->getStatusCode());
        self::assertSame(200, $controller->tenantLive('demo')->getStatusCode());
        self::assertSame('{"status":"ok"}', $controller->tenantLive('demo')->getContent());
        self::assertSame(200, $controller->metrics()->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $controller->metrics()->headers->get('Content-Type'));
        self::assertSame(['status' => 'not_ready', 'checks' => ['tenant' => false, 'database' => false, 'dependencies' => false]], json_decode($controller->tenantReady('unknown')->getContent(), true));
        $response = $controller->tenantReady('demo');
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['status' => 'not_ready', 'checks' => ['tenant' => true, 'database' => false, 'dependencies' => false]], json_decode($response->getContent(), true));
    }
}
