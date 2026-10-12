<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Controller\AdminTimezonesApiController;
use PHPUnit\Framework\TestCase;

final class AdminTimezonesApiControllerTest extends TestCase
{
    public function testListContainsEveryUsablePhpIdentifierIncludingAliases(): void
    {
        $response = (new AdminTimezonesApiController())->list();
        self::assertSame(200, $response->getStatusCode());
        $timezones = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains('Europe/Paris', $timezones);
        self::assertContains('UTC', $timezones);
        foreach ($timezones as $timezone) {
            self::assertSame($timezone, (new \DateTimeZone($timezone))->getName());
        }
        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $timezone) {
            try {
                new \DateTimeZone($timezone);
            } catch (\Exception) {
                continue;
            }
            self::assertContains($timezone, $timezones);
        }
        $sorted = $timezones;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $timezones);
    }
}
