<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\EventListener\TenantRequestListener;
use App\EventListener\TenantSessionNameListener;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use App\Tenant\TenantAwareCachePool;
use App\Tenant\TenantConnectionMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TenantAdapterIsolationTest extends TestCase
{
    public function testSuccessiveTenantsKeepSeparateCacheAndDatabase(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tenants-');
        file_put_contents($file, '{"alpha":{"db":"db_a"},"beta":{"db":"db_b"}}');
        try {
            $registry = new TenantRegistry($file, false);
            $resolver = new TenantIdentifierResolver();
            $context = new TenantContext($registry, $resolver, 'alpha');
            $context->setSlug('alpha');
            $connection = $this->createMock(Connection::class);
            $connection->method('isConnected')->willReturn(true);
            $connection->expects(self::exactly(2))->method('close');
            $listener = new TenantRequestListener($context, $resolver, $registry, $connection);
            $cache = new TenantAwareCachePool(new ArrayAdapter(), $context);
            $driver = $this->createMock(Driver::class);
            $databases = [];
            $driver->expects(self::exactly(3))->method('connect')->willReturnCallback(function (array $params) use (&$databases): Driver\Connection {
                $databases[] = $params['dbname'];
                return $this->createMock(Driver\Connection::class);
            });
            $wrapped = (new TenantConnectionMiddleware($context))->wrap($driver);
            foreach (['alpha', 'beta', 'alpha'] as $slug) {
                $request = Request::create('/api/v2/shop/products');
                $request->headers->set('X-TodaTempo-Tenant', $slug);
                $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));
                $wrapped->connect(['dbname' => 'ignored', 'user' => 'fixture']);
                self::assertSame($slug, $cache->get('same-key', static fn () => $slug));
            }
            self::assertSame(['db_a', 'db_b', 'db_a'], $databases);
            $context->setSlug('beta');
            $cache->clear();
            self::assertFalse($cache->hasItem('same-key'));
            $context->setSlug('alpha');
            self::assertSame('alpha', $cache->getItem('same-key')->get());
            $cache->reset();
            $context->setSlug('beta');
            self::assertSame('beta', $cache->get('after-reset', static fn () => 'beta'));
            $context->setSlug('alpha');
            self::assertSame('alpha', $cache->get('after-reset', static fn () => 'alpha'));
        } finally {
            unlink($file);
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSessionCookieNamesFollowSuccessiveExplicitTenants(): void
    {
        $context = new TenantContext(new TenantRegistry('/nonexistent', false), new TenantIdentifierResolver(), 'demo');
        $listener = new TenantSessionNameListener($context);
        $original = ini_get('session.name');
        try {
            foreach (['alpha' => 'SBSESSALPHA', 'beta' => 'SBSESSBETA'] as $slug => $name) {
                $context->setSlug($slug);
                $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
                self::assertSame($name, ini_get('session.name'));
            }
        } finally {
            ini_set('session.name', $original);
        }
    }
}
