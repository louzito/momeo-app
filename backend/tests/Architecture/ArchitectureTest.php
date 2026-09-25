<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase
{
    public function testGuardRejectsRegressionsAndAllowsTechnicalBoundaries(): void
    {
        $verify = require __DIR__.'/rules-cases.php';
        self::assertSame(19, $verify());
    }

    public function testRepositoryArchitecture(): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/scripts/check-architecture.php').' 2>&1', $output, $status);
        self::assertSame(0, $status, implode("\n", $output));
    }
}
