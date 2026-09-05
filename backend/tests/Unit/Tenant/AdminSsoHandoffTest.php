<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Controller\AdminSsoHandoffController;
use App\Entity\User\AdminUser;
use App\Tenant\AdminLoginTicketStore;
use App\Tenant\TenantAwareCachePool;
use App\Tenant\TenantContext;
use App\Tenant\TenantIdentifierResolver;
use App\Tenant\TenantRegistry;
use App\Tenant\TenantUrlGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

final class AdminSsoHandoffTest extends TestCase
{
    private string $registryFile;
    private TenantContext $context;
    private AdminLoginTicketStore $tickets;
    private AdminSsoHandoffController $controller;

    protected function setUp(): void
    {
        $this->registryFile = tempnam(sys_get_temp_dir(), 'sso-registry-');
        file_put_contents($this->registryFile, json_encode([
            'alpha' => ['db' => 'alpha', 'status' => 'active'],
            'beta' => ['db' => 'beta', 'status' => 'active'],
        ]));
        $registry = new TenantRegistry($this->registryFile, false);
        $this->context = new TenantContext($registry, new TenantIdentifierResolver(), 'beta');
        $repository = $this->createMock(EntityRepository::class);
        $admin = new AdminUser();
        $admin->setEnabled(true);
        $repository->method('findOneBy')->willReturn($admin);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $this->tickets = new AdminLoginTicketStore(
            $this->context, $registry, $this->createMock(Connection::class), $manager,
            new TenantAwareCachePool(new ArrayAdapter(), $this->context),
        );
        $this->controller = new AdminSsoHandoffController(
            $this->tickets, $this->context,
            new TenantUrlGenerator($registry, 'https://public.test/todatempo-app'),
        );
    }

    protected function tearDown(): void
    {
        unlink($this->registryFile);
    }

    public function testPrefixedHandoffIssuesASecureCookieForTheActualApiPathAndCannotBeReplayed(): void
    {
        $code = $this->tickets->create('alpha', 'owner@example.test', 'Owner');
        $request = Request::create('https://public.test/todatempo-app/backend/api/v2/admin/todatempo/sso/handoff', 'POST', ['code' => $code], [], [], [
            'SCRIPT_NAME' => '/todatempo-app/backend/index.php',
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
        ]);
        $response = ($this->controller)($request);
        self::assertSame('https://public.test/todatempo-app/alpha/admin/login?sso=1', $response->getTargetUrl());
        $cookie = $response->headers->getCookies()[0];
        self::assertSame('/todatempo-app/backend/api/v2/admin/todatempo/sso/session', $cookie->getPath());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('alpha', $this->tickets->consumeBrowserSession($cookie->getValue())['slug']);
        self::assertStringEndsWith('?sso=error', ($this->controller)($request)->getTargetUrl());
    }

    public function testTicketForAnotherTenantNeverCreatesABrowserSession(): void
    {
        $code = $this->tickets->create('alpha', 'owner@example.test', 'Owner');
        $this->context->setSlug('beta');
        $response = ($this->controller)(Request::create('/api/v2/admin/todatempo/sso/handoff', 'POST', ['code' => $code]));
        self::assertStringEndsWith('/beta/admin/login?sso=error', $response->getTargetUrl());
        self::assertSame([], $response->headers->getCookies());
    }
}
