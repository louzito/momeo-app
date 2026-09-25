<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Controller\AdminSsoHandoffController;
use App\Controller\AdminSsoController;
use App\Service\Tenant\AdminSsoSession;
use App\Service\Security\TeamPermissions;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\NullLogger;
use App\Entity\User\AdminUser;
use App\Service\Tenant\AdminLoginTicketStore;
use App\Tenant\TenantAwareCachePool;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use App\Service\Tenant\TenantUrlGenerator;
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
    private AdminSsoController $sessionController;
    private AdminUser $admin;
    private JWTTokenManagerInterface $jwt;
    private TenantAwareCachePool $cache;

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
        $admin = $this->admin = new AdminUser();
        $admin->setEmail('owner@example.test');
        $admin->setEnabled(true);
        $repository->method('findOneBy')->willReturn($admin);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $this->cache = new TenantAwareCachePool(new ArrayAdapter(), $this->context);
        $this->tickets = new AdminLoginTicketStore(
            $this->context, $registry, $this->createMock(Connection::class), $manager,
            $this->cache,
        );
        $this->jwt = $this->createMock(JWTTokenManagerInterface::class);
        $session = new AdminSsoSession($this->tickets, $manager, $this->jwt, new NullLogger());
        $this->sessionController = new AdminSsoController($session, new NullLogger());
        $this->controller = new AdminSsoHandoffController(
            $session, $this->context,
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
        self::assertSame('lax', $cookie->getSameSite());
        self::assertNull($cookie->getDomain());
        self::assertEqualsWithDelta(time() + 60, $cookie->getExpiresTime(), 2);
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
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

    public function testSessionIssuesTheSamePayloadAndClearsTheCookieOnlyOnSuccess(): void
    {
        $code = $this->tickets->create('alpha', 'owner@example.test', ' Owner ');
        $handoff = ($this->controller)(Request::create('/api/v2/admin/todatempo/sso/handoff', 'POST', ['code' => $code]));
        $value = $handoff->headers->getCookies()[0]->getValue();
        $this->jwt->expects(self::once())->method('create')->with($this->admin)->willReturn('signed-token');
        $request = Request::create('https://public.test/todatempo-app/backend/api/v2/admin/todatempo/sso/session', 'POST', [], ['TODATEMPO_ADMIN_SSO' => $value], [], [
            'SCRIPT_NAME' => '/todatempo-app/backend/index.php',
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
        ]);
        $response = ($this->sessionController)($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['token' => 'signed-token', 'admin' => [
            'email' => 'owner@example.test', 'name' => 'Owner', 'role' => $this->admin->getTeamRole()->value,
            'permissions' => TeamPermissions::forRole($this->admin->getTeamRole()), 'staffMemberId' => null,
        ]], json_decode($response->getContent(), true));
        $cookie = $response->headers->getCookies()[0];
        self::assertSame('/todatempo-app/backend/api/v2/admin/todatempo/sso/session', $cookie->getPath());
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertLessThan(time(), $cookie->getExpiresTime());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $replay = ($this->sessionController)($request);
        self::assertSame(401, $replay->getStatusCode());
        self::assertSame('{"error":"invalid_sso_session"}', $replay->getContent());
        self::assertSame([], $replay->headers->getCookies());
    }

    public function testDisabledAdminConsumesTheLegacyCookieWithoutIssuingAJwt(): void
    {
        $code = $this->tickets->create('alpha', 'owner@example.test', 'Owner');
        $response = ($this->controller)(Request::create('/handoff', 'POST', ['code' => $code]));
        $this->admin->setEnabled(false);
        $this->jwt->expects(self::never())->method('create');
        $request = Request::create('/session', 'POST', [], ['MOMEO_ADMIN_SSO' => $response->headers->getCookies()[0]->getValue()]);
        $response = ($this->sessionController)($request);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":"admin_not_found"}', $response->getContent());
        self::assertSame([], $response->headers->getCookies());
        self::assertSame('{"error":"invalid_sso_session"}', ($this->sessionController)($request)->getContent());
    }

    public function testExpiredBrowserSessionAndWrongTenantAreRejectedBeforeJwtCreation(): void
    {
        $this->context->setSlug('alpha');
        $value = $this->tickets->createBrowserSession(['slug' => 'alpha', 'email' => 'owner@example.test', 'name' => 'Owner']);
        $key = 'momeo.admin_browser_session.'.hash('sha256', $value);
        $item = $this->cache->getItem($key);
        $item->expiresAfter(-1);
        $this->cache->save($item);
        $this->jwt->expects(self::never())->method('create');
        $response = ($this->sessionController)(Request::create('/session', 'POST', [], ['TODATEMPO_ADMIN_SSO' => $value]));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":"invalid_sso_session"}', $response->getContent());
        $value = $this->tickets->createBrowserSession(['slug' => 'alpha', 'email' => 'owner@example.test', 'name' => 'Owner']);
        $this->context->setSlug('beta');
        self::assertSame(401, ($this->sessionController)(Request::create('/session', 'POST', [], ['TODATEMPO_ADMIN_SSO' => $value]))->getStatusCode());
    }

    public function testCanonicalCookieTakesPrecedenceOverLegacyCookie(): void
    {
        $this->context->setSlug('alpha');
        $value = $this->tickets->createBrowserSession(['slug' => 'alpha', 'email' => 'owner@example.test', 'name' => 'Owner']);
        $this->jwt->expects(self::never())->method('create');
        $response = ($this->sessionController)(Request::create('/session', 'POST', [], ['TODATEMPO_ADMIN_SSO' => '', 'MOMEO_ADMIN_SSO' => $value]));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('alpha', $this->tickets->consumeBrowserSession($value)['slug']);
    }

    public function testExpiredTicketDoesNotSetACookie(): void
    {
        $code = $this->tickets->create('alpha', 'owner@example.test', 'Owner');
        $item = $this->cache->getItem('momeo.admin_login.'.hash('sha256', $code));
        $item->expiresAfter(-1);
        $this->cache->save($item);
        $response = ($this->controller)(Request::create('/handoff', 'POST', ['code' => $code]));
        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/alpha/admin/login?sso=error', $response->getTargetUrl());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testJwtInfrastructureFailureIsNotConvertedToASessionRejection(): void
    {
        $this->context->setSlug('alpha');
        $value = $this->tickets->createBrowserSession(['slug' => 'alpha', 'email' => 'owner@example.test', 'name' => 'Owner']);
        $this->jwt->method('create')->willThrowException(new \RuntimeException('signing unavailable'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signing unavailable');
        ($this->sessionController)(Request::create('/session', 'POST', [], ['TODATEMPO_ADMIN_SSO' => $value]));
    }

    public function testCanonicalAndLegacyRoutesRemainPostOnly(): void
    {
        foreach ([AdminSsoController::class => 'session', AdminSsoHandoffController::class => 'handoff'] as $class => $suffix) {
            $attributes = (new \ReflectionMethod($class, '__invoke'))->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);
            self::assertCount(2, $attributes);
            $routes = array_map(static fn (\ReflectionAttribute $attribute) => $attribute->newInstance(), $attributes);
            self::assertSame('/api/v2/admin/todatempo/sso/'.$suffix, $routes[0]->getPath());
            self::assertSame('todatempo_api_admin_sso_'.$suffix, $routes[0]->getName());
            self::assertSame('/api/v2/admin/momeo/sso/'.$suffix, $routes[1]->getPath());
            self::assertSame('momeo_api_admin_sso_'.$suffix.'_legacy', $routes[1]->getName());
            self::assertSame(['POST'], $routes[0]->getMethods());
            self::assertSame(['POST'], $routes[1]->getMethods());
        }
    }

    public function testMissingExplicitTenantNeverFallsBackToDefaultDuringHandoff(): void
    {
        $saved = [];
        foreach (['TODATEMPO_TENANT', 'SKYBOOK_TENANT'] as $key) {
            $saved[$key] = $_SERVER[$key] ?? null;
            $_SERVER[$key] = '';
        }
        $this->context->setSlug(null);
        try {
            ($this->controller)(Request::create('/handoff', 'POST', ['code' => 'invalid']));
            self::fail('An explicit tenant is required.');
        } catch (\Symfony\Component\HttpKernel\Exception\BadRequestHttpException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertSame('Centre SSO absent.', $exception->getMessage());
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    public function testMalformedCookieIsStillAnUnauthorizedSession(): void
    {
        $this->jwt->expects(self::never())->method('create');
        $response = ($this->sessionController)(Request::create('/session', 'POST', [], ['TODATEMPO_ADMIN_SSO' => ['invalid']]));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":"invalid_sso_session"}', $response->getContent());
    }
}
