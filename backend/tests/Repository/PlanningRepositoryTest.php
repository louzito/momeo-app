<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Planning;
use PHPUnit\Framework\TestCase;

final class PlanningRepositoryTest extends TestCase
{
    public function testPlanningDeduplicatesCompatibleServices(): void
    {
        $planning = new Planning();
        $planning->setServiceCodes(['jump_a', 'jump_a', 'jump_b']);

        self::assertSame(['jump_a', 'jump_b'], $planning->getServiceCodes());
    }

    public function testPlanningDefaultsToEstablishmentScope(): void
    {
        self::assertNull((new Planning())->getStaffMember());
    }
}
