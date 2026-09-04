<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ClientProfile;
use PHPUnit\Framework\TestCase;

final class ClientProfileTest extends TestCase
{
    public function testConsentChangesAreTimestampedAndAppendOnly(): void
    {
        $profile = new ClientProfile(' Client@Example.test ');
        $profile->recordConsent('marketing', true, 'admin@example.test');
        $profile->recordConsent('marketing', false, 'other@example.test');

        self::assertSame('client@example.test', $profile->getBookingEmail());
        self::assertFalse($profile->getConsents()['marketing']);
        self::assertCount(2, $profile->getConsentHistory());
        self::assertSame('admin@example.test', $profile->getConsentHistory()[0]['recordedBy']);
        self::assertNotEmpty($profile->getConsentHistory()[0]['recordedAt']);
    }

    public function testTagsAreCleanedAndDeduplicated(): void
    {
        $profile = new ClientProfile('client@example.test');
        $profile->setTags([' VIP ', '', 'VIP', 'fidèle']);

        self::assertSame(['VIP', 'fidèle'], $profile->getTags());
    }
}
