<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Security\TeamPermission;
use App\Security\TeamPermissions;
use App\Security\TeamRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TeamPermissionsMatrixTest extends TestCase
{
    /** @return iterable<string, array{TeamRole, list<TeamPermission>}> */
    public static function roles(): iterable
    {
        yield 'owner' => [TeamRole::Owner, TeamPermission::cases()];
        yield 'manager' => [TeamRole::Manager, [TeamPermission::Agenda, TeamPermission::Clients, TeamPermission::Finances, TeamPermission::Catalog]];
        yield 'reception' => [TeamRole::Reception, [TeamPermission::Agenda, TeamPermission::Clients]];
        yield 'practitioner' => [TeamRole::Practitioner, [TeamPermission::Agenda]];
    }

    /** @param list<TeamPermission> $granted */
    #[DataProvider('roles')]
    public function testRoleHasExactlyItsBusinessPermissions(TeamRole $role, array $granted): void
    {
        foreach (TeamPermission::cases() as $permission) {
            self::assertSame(
                in_array($permission, $granted, true),
                TeamPermissions::allows($role, $permission),
                sprintf('%s / %s', $role->value, $permission->value),
            );
        }
    }
}
