<?php

declare(strict_types=1);

namespace App\Security;

final class TeamPermissions
{
    /** @return list<string> */
    public static function forRole(TeamRole $role): array
    {
        return array_map(static fn (TeamPermission $permission): string => $permission->value, match ($role) {
            TeamRole::Owner => TeamPermission::cases(),
            TeamRole::Manager => [TeamPermission::Agenda, TeamPermission::Clients, TeamPermission::Finances, TeamPermission::Catalog],
            TeamRole::Reception => [TeamPermission::Agenda, TeamPermission::Clients],
            TeamRole::Practitioner => [TeamPermission::Agenda],
        });
    }

    public static function allows(TeamRole $role, TeamPermission $permission): bool
    {
        return in_array($permission->value, self::forRole($role), true);
    }
}
