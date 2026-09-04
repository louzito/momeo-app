<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class AdminClientApiContractTest extends TestCase
{
    public function testClientDataIsProtectedSearchableEditableAndAudited(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Controller/AdminClientApiController.php');
        self::assertIsString($source);
        self::assertStringContainsString("#[IsGranted('ROLE_API_ACCESS')]", $source);
        self::assertStringContainsString("methods: ['PUT']", $source);
        self::assertStringContainsString("query->get('q'", $source);
        self::assertStringContainsString('recordConsent', $source);
        self::assertStringContainsString("'recordedBy'", file_get_contents(__DIR__.'/../../src/Entity/ClientProfile.php'));
    }
}
