<?php

declare(strict_types=1);

namespace App\Tests\Gdpr;

use App\Gdpr\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    public function testItComputesConfigurableCutoffs(): void
    {
        $policy = new RetentionPolicy(36, 90, 10);
        $now = new \DateTimeImmutable('2026-09-04 12:00:00 UTC');
        self::assertSame('2023-09-04', $policy->bookingCutoff($now)->format('Y-m-d'));
        self::assertSame('2026-06-06', $policy->waitlistCutoff($now)->format('Y-m-d'));
        self::assertSame('2016-09-04', $policy->invoiceCutoff($now)->format('Y-m-d'));
    }

    public function testItRejectsAnUnsafeDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetentionPolicy(0, 90, 10);
    }
}
