<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class ObservabilityContractTest extends TestCase
{
    public function testHealthChecksAreDistinctAndResponsesDoNotExposeConfiguration(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Controller/ObservabilityController.php');
        $checker = (string) file_get_contents(__DIR__.'/../../src/Observability/HealthChecker.php');

        self::assertStringContainsString("'/health/live'", $controller);
        self::assertStringContainsString("'/health/ready'", $controller);
        self::assertStringContainsString("'/health/tenants/{tenant", $controller);
        self::assertStringContainsString("executeQuery('SELECT 1')", $checker);
        self::assertStringNotContainsString("getDatabaseName()", $controller);
        self::assertStringNotContainsString('$url', $controller);
    }
}
