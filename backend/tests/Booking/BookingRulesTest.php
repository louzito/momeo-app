<?php

declare(strict_types=1);

namespace App\Tests\Booking;

use App\Booking\BookingRules;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class BookingRulesTest extends TestCase
{
    public function testItNormalizesAndBoundsPersistedRules(): void
    {
        $rules = new BookingRules($this->createMock(EntityManagerInterface::class));

        self::assertSame([
            'minimumNoticeHours' => 8760,
            'maximumAdvanceDays' => 1,
            'cancellationNoticeHours' => 12,
            'bufferBeforeMinutes' => 0,
            'bufferAfterMinutes' => 1440,
            'customerPolicy' => 'Annulation sans frais.',
        ], $rules->normalize([
            'minimumNoticeHours' => 99999,
            'maximumAdvanceDays' => 0,
            'cancellationNoticeHours' => '12',
            'bufferBeforeMinutes' => -1,
            'bufferAfterMinutes' => 2000,
            'customerPolicy' => '  Annulation sans frais.  ',
        ]));
    }

    public function testDefaultsAreStable(): void
    {
        $rules = new BookingRules($this->createMock(EntityManagerInterface::class));
        self::assertSame(BookingRules::DEFAULTS, $rules->normalize([]));
    }
}
