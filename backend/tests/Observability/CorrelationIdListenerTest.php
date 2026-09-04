<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\CorrelationIdListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CorrelationIdListenerTest extends TestCase
{
    public function testItKeepsAValidClientIdAndReturnsIt(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/health/live', server: ['HTTP_X_CORRELATION_ID' => 'request-1234']);
        $listener = new CorrelationIdListener();
        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $response = new Response();
        $listener->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        self::assertSame('request-1234', $response->headers->get(CorrelationIdListener::HEADER));
    }

    public function testItReplacesAnUnsafeId(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/', server: ['HTTP_X_CORRELATION_ID' => "secret\nvalue"]);
        (new CorrelationIdListener())->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $request->attributes->get(CorrelationIdListener::ATTRIBUTE));
    }
}
