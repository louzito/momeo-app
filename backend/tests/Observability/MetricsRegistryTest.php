<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\MetricsRegistry;
use PHPUnit\Framework\TestCase;

final class MetricsRegistryTest extends TestCase
{
    public function testItPersistsAndRendersTenantCountersWithoutArbitraryLabels(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'metrics-');
        self::assertIsString($file);
        try {
            $registry = new MetricsRegistry($file);
            $registry->increment('webhook_received', 'skyline');
            $registry->increment('webhook_received', 'skyline');
            $registry->increment('not-valid', 'skyline');

            $output = $registry->render();
            self::assertStringContainsString('# TYPE todatempo_worker_message_failed_total counter', $output);
            self::assertStringContainsString("todatempo_webhook_received_total{tenant=\"skyline\"} 2\n", $output);
            self::assertStringNotContainsString('not-valid', $output);
        } finally {
            @unlink($file);
        }
    }
}
