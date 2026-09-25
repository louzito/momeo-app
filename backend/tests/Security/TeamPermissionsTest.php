<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\Security\TeamPermission;
use App\Service\Security\TeamPermissions;
use App\Service\Security\TeamRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TeamPermissionsTest extends TestCase
{
    public function testPreviouslySerializedSessionRoleRemainsReadable(): void
    {
        self::assertSame(TeamRole::Owner, unserialize('E:27:"App\\Security\\TeamRole:Owner";'));
    }

    /** @return iterable<string, array{TeamRole, TeamPermission, bool}> */
    public static function decisions(): iterable
    {
        yield 'owner settings' => [TeamRole::Owner, TeamPermission::Settings, true];
        yield 'manager finances' => [TeamRole::Manager, TeamPermission::Finances, true];
        yield 'manager settings forbidden' => [TeamRole::Manager, TeamPermission::Settings, false];
        yield 'reception clients' => [TeamRole::Reception, TeamPermission::Clients, true];
        yield 'reception finances forbidden' => [TeamRole::Reception, TeamPermission::Finances, false];
        yield 'practitioner agenda' => [TeamRole::Practitioner, TeamPermission::Agenda, true];
        yield 'practitioner clients forbidden' => [TeamRole::Practitioner, TeamPermission::Clients, false];
    }

    #[DataProvider('decisions')]
    public function testExplicitPermissionMatrix(TeamRole $role, TeamPermission $permission, bool $expected): void
    {
        self::assertSame($expected, TeamPermissions::allows($role, $permission));
    }
}
