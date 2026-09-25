<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ImageUploadValidator;
use App\Security\SensitiveEndpointRateLimiter;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHardeningContractTest extends TestCase
{
    public function testSensitiveEndpointsAreRateLimitedPerTenantAndClient(): void
    {
        $context = new TenantContext(new TenantRegistry('/nonexistent', false), new TenantIdentifierResolver(), 'demo');
        $listener = new SensitiveEndpointRateLimiter(new \App\Service\Security\SensitiveEndpointRateLimiter(new ArrayAdapter(), $context));
        foreach ([['alpha', '127.0.0.1'], ['beta', '127.0.0.1'], ['alpha', '127.0.0.2']] as [$tenant, $ip]) {
            $context->setSlug($tenant);
            for ($i = 0; $i < 5; ++$i) {
                $event = $this->request('/api/v2/shop/customers/token', 'POST', $ip);
                $listener->onKernelRequest($event);
                self::assertNull($event->getResponse());
            }
            $event = $this->request('/api/v2/shop/customers/token', 'POST', $ip);
            $listener->onKernelRequest($event);
            self::assertSame(429, $event->getResponse()?->getStatusCode());
            self::assertSame(['error' => 'too_many_requests'], json_decode($event->getResponse()->getContent(), true));
            self::assertSame('60', $event->getResponse()->headers->get('Retry-After'));
        }
        $context->setSlug('alpha');
        $event = $this->request('/api/v2/shop/customers/token', 'POST', '127.0.0.1');
        $listener->onKernelRequest($event);
        self::assertSame(429, $event->getResponse()?->getStatusCode());
        for ($i = 0; $i < 30; ++$i) {
            $event = $this->request('/api/v2/shop/gift-vouchers/check');
            $listener->onKernelRequest($event);
            self::assertNull($event->getResponse());
        }
        $event = $this->request('/api/v2/shop/gift-vouchers/check');
        $listener->onKernelRequest($event);
        self::assertSame(429, $event->getResponse()?->getStatusCode());
        $event = $this->request('/api/v2/shop/products');
        $listener->onKernelRequest($event);
        self::assertNull($event->getResponse());
    }

    public function testAdminImagesAreCheckedServerSide(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'image-');
        try {
            file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1sAAAAASUVORK5CYII='));
            $listener = new ImageUploadValidator(new \App\Service\Security\ImageUploadValidator());
            foreach (['pixel.png' => null, 'pixel.jpg' => 422, 'pixel.php' => 422] as $name => $status) {
                $event = $this->request('/api/v2/admin/products/1/images', 'POST');
                $event->getRequest()->files->set('images', ['nested' => new UploadedFile($file, $name, null, null, true)]);
                $listener->onKernelRequest($event);
                self::assertSame($status, $event->getResponse()?->getStatusCode());
                if ($status !== null) {
                    self::assertSame('invalid_image', json_decode($event->getResponse()->getContent(), true)['error']);
                }
            }
            file_put_contents($file, str_repeat('x', 5_242_881));
            clearstatcache(true, $file);
            self::assertSame('Image invalide ou superieure a 5 Mio.', (new \App\Service\Security\ImageUploadValidator())->validate(new UploadedFile($file, 'large.png', null, null, true)));
        } finally {
            unlink($file);
        }
    }

    public function testJwtLifetimeIsBounded(): void
    {
        self::assertStringContainsString('token_ttl: 900', (string) file_get_contents(__DIR__.'/../../config/packages/lexik_jwt_authentication.yaml'));
    }

    public function testSessionLoginsKeepCsrfAndCorsIsNotWildcarded(): void
    {
        self::assertGreaterThanOrEqual(2, substr_count((string) file_get_contents(__DIR__.'/../../config/packages/security.yaml'), 'enable_csrf: true'));
        $event = new \Symfony\Component\HttpKernel\Event\ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new \Symfony\Component\HttpFoundation\Response());
        (new \App\Security\HttpSecurityHeadersSubscriber())->onKernelResponse($event);
        self::assertSame('nosniff', $event->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertFalse($event->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    private function request(string $path, string $method = 'GET', string $ip = '127.0.0.1'): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create($path, $method, server: ['REMOTE_ADDR' => $ip]), HttpKernelInterface::MAIN_REQUEST);
    }
}
