<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AdminPasswordLoginSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AdminPasswordLoginSubscriberTest extends TestCase
{
    public function testPasswordTokenEndpointIsDenied(): void
    {
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create('/api/v2/admin/administrators/token', 'POST'), HttpKernelInterface::MAIN_REQUEST);
        $this->expectException(AccessDeniedHttpException::class);
        (new AdminPasswordLoginSubscriber())($event);
    }

    public function testSsoAndCustomerLoginAreUnaffected(): void
    {
        foreach (['/api/v2/admin/todatempo/sso/session', '/api/v2/admin/todatempo/sso/handoff', '/api/v2/shop/customers/token'] as $path) {
            $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create($path, 'POST'), HttpKernelInterface::MAIN_REQUEST);
            (new AdminPasswordLoginSubscriber())($event);
            self::assertFalse($event->hasResponse());
        }
    }
}
