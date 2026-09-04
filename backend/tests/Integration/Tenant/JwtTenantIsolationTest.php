<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenant;

use App\Tenant\JwtTenantListener;
use App\Tenant\TenantContext;
use App\Tenant\TenantIdentifierResolver;
use App\Tenant\TenantRegistry;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use PHPUnit\Framework\TestCase;

final class JwtTenantIsolationTest extends TestCase
{
    private TenantContext $context;
    private JwtTenantListener $listener;

    protected function setUp(): void
    {
        $registry = new TenantRegistry(__DIR__.'/../../Fixtures/tenants.json', false);
        $this->context = new TenantContext($registry, new TenantIdentifierResolver(), 'demo');
        $this->listener = new JwtTenantListener($this->context);
    }

    public function testIssuedTokenIsBoundToCurrentTenant(): void
    {
        $event = new JWTCreatedEvent(['username' => 'owner@example.test'], new \stdClass());

        $this->listener->onJwtCreated($event);

        self::assertSame('demo', $event->getData()['tenant']);
    }

    public function testTokenFromAnotherTenantIsRejected(): void
    {
        $event = new JWTDecodedEvent(['tenant' => 'another-center']);

        $this->listener->onJwtDecoded($event);

        self::assertFalse($event->isValid(), 'A JWT issued for another tenant must not cross the tenant boundary.');
    }

    public function testTokenForCurrentTenantRemainsValid(): void
    {
        $event = new JWTDecodedEvent(['tenant' => 'demo']);

        $this->listener->onJwtDecoded($event);

        self::assertTrue($event->isValid());
    }
}
