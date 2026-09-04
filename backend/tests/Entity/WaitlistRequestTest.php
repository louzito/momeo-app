<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WaitlistNotification;
use App\Entity\WaitlistRequest;
use PHPUnit\Framework\TestCase;

final class WaitlistRequestTest extends TestCase
{
    public function testExplicitUnsubscriptionIsIdempotent(): void
    {
        $request = new WaitlistRequest();
        self::assertTrue($request->isActive());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $request->getUnsubscribeToken());

        $request->unsubscribe();
        $firstDate = $request->getUnsubscribedAt();
        $request->unsubscribe();

        self::assertFalse($request->isActive());
        self::assertSame(WaitlistRequest::STATUS_UNSUBSCRIBED, $request->getStatus());
        self::assertSame($firstDate, $request->getUnsubscribedAt());
    }

    public function testNotificationKeyIsStableForARequestAndSlot(): void
    {
        $request = new WaitlistRequest();
        $id = new \ReflectionProperty($request, 'id');
        $id->setValue($request, 42);
        $start = new \DateTimeImmutable('2027-01-10 10:00:00 UTC');
        $end = new \DateTimeImmutable('2027-01-10 11:00:00 UTC');

        $first = new WaitlistNotification($request, $start, $end);
        $duplicate = new WaitlistNotification($request, $start, $end);

        self::assertSame($first->getIdempotencyKey(), $duplicate->getIdempotencyKey());
        self::assertNotSame($first->getIdempotencyKey(), (new WaitlistNotification($request, $start->modify('+1 hour'), $end->modify('+1 hour')))->getIdempotencyKey());
    }
}
