<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use App\Configuration\SiteConfigDocument;
use PHPUnit\Framework\TestCase;

final class SiteConfigDocumentTest extends TestCase
{
    public function testItReadsLegacyConfiguration(): void
    {
        self::assertSame(['name' => 'Legacy'], SiteConfigDocument::published(['name' => 'Legacy']));
    }

    public function testItOnlyExposesPublishedVersion(): void
    {
        $document = ['schemaVersion' => 1, 'draft' => ['name' => 'Draft'], 'published' => ['name' => 'Live']];
        self::assertSame(['name' => 'Live'], SiteConfigDocument::published($document));
    }
}
