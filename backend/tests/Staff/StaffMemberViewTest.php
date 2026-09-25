<?php

declare(strict_types=1);

namespace App\Tests\Staff;

use App\Entity\StaffMember;
use App\Entity\User\AdminUser;
use App\Service\Security\TeamRole;
use App\Service\Staff\StaffMemberView;
use PHPUnit\Framework\TestCase;

final class StaffMemberViewTest extends TestCase
{
    public function testOnlyExplicitAccountFieldsAreExposed(): void
    {
        $member = new StaffMember();
        $member->setFirstName('Ada');
        $member->setLastName('Lovelace');
        $member->setEmail('contact@example.test');
        $member->setServiceCodes(['massage', 'massage']);
        $view = new StaffMemberView();
        $hidden = $view->admin($member, null);
        self::assertSame(explode(' ', 'id firstName lastName displayName email phone jobTitle bio color active bookable serviceCodes workingHours position accountEmail role createdAt updatedAt'), array_keys($hidden));
        self::assertSame('Ada Lovelace', $hidden['displayName']);
        self::assertSame(['massage'], $hidden['serviceCodes']);
        self::assertNull($hidden['accountEmail']);
        self::assertNull($hidden['role']);

        $account = new AdminUser();
        $account->setEmail('login@example.test');
        $account->setPassword('private-password-hash');
        $account->setTeamRole(TeamRole::Owner);
        $visible = $view->admin($member, $account);
        self::assertSame(array_keys($hidden), array_keys($visible));
        self::assertSame('login@example.test', $visible['accountEmail']);
        self::assertSame('contact@example.test', $visible['email']);
        self::assertSame(TeamRole::Owner->value, $visible['role']);
        self::assertStringNotContainsString('private-password-hash', json_encode($visible, JSON_THROW_ON_ERROR));
        self::assertSame($hidden, $view->admin($member, null));
    }
}
