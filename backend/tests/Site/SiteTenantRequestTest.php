<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\EventListener\TenantRequestListener;
use App\Service\Tenant\{TenantContext, TenantIdentifierResolver, TenantRegistry};
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SiteTenantRequestTest extends TestCase
{
    public static function paths(): iterable
    {
        yield ['/api/v2/admin/site/pages'];
        yield ['/api/v2/admin/site/publish'];
        yield ['/api/v2/admin/site/import'];
        yield ['/api/v2/admin/site/pages/123/preview'];
        yield ['/api/v2/shop/site/roles/home'];
        yield ['/api/v2/shop/site/html/contact'];
        yield ['/api/v2/shop/site/html/sitemap.xml'];
        yield ['/api/v2/admin/site/appearance'];
        yield ['/api/v2/shop/checkout'];
        yield ['/api/v2/admin/site/media'];
        yield ['/api/v2/admin/site/media/0123456789abcdef0123456789abcdef'];
        yield ['/api/v2/shop/site/pages/contact'];
        yield ['/api/v2/shop/site/menus/main'];
        yield ['/api/v2/shop/site/navigation'];
        yield ['/api/v2/admin/site/menus/footer'];
    }
    #[DataProvider('paths')]
    public function testNewSiteApisNeverFallBackToDefaultTenant(string $path): void
    {
        $registry = new TenantRegistry(__DIR__.'/../Fixtures/tenants.json', false);
        $resolver = new TenantIdentifierResolver();
        $context = new TenantContext($registry, $resolver, 'demo');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeQuery');
        $listener = new TenantRequestListener($context, $resolver, $registry, $connection);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create($path), HttpKernelInterface::MAIN_REQUEST);
        $this->expectException(NotFoundHttpException::class);
        $listener($event);
    }
}
