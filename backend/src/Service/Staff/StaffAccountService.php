<?php

declare(strict_types=1);

namespace App\Service\Staff;

use App\Entity\StaffMember;
use App\Entity\User\AdminUser;
use App\Service\Security\TeamRole;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/** Account linking policy; called inside StaffManagementService's transaction. */
final class StaffAccountService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function linkedAccount(StaffMember $member): ?AdminUser
    {
        if ($member->getId() === null) return null;

        return $this->entityManager->getRepository(AdminUser::class)->findOneBy(['staffMember' => $member]);
    }

    /** Validate all rules before returning the write operation. No account is created here.
     * @param array<string, mixed> $payload
     */
    public function prepare(StaffMember $member, array $payload): \Closure
    {
        if (!array_key_exists('accountEmail', $payload) && !array_key_exists('role', $payload)) {
            return static function (): void {};
        }
        $role = TeamRole::tryFrom((string) ($payload['role'] ?? TeamRole::Practitioner->value));
        if ($role === null) {
            throw new InvalidStaffInput('Le rôle doit être owner, manager, reception ou practitioner.');
        }

        // Serialize account edits in this tenant, including owner counts and unique links.
        // Refresh avoids decisions based on a previously loaded account before the lock.
        $accounts = $this->entityManager->createQueryBuilder()
            ->select('account')->from(AdminUser::class, 'account')->orderBy('account.id', 'ASC')
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)->getResult();
        $current = $this->linkedAccount($member);
        $email = mb_strtolower(trim((string) ($payload['accountEmail'] ?? '')));
        if ($email === '') {
            if ($current !== null && !$this->canRemoveOwner($current, $accounts)) {
                throw new InvalidStaffInput('Le dernier propriétaire ne peut pas être dissocié.');
            }
            return static function () use ($current): void { $current?->setStaffMember(null); };
        }

        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if (!$admin instanceof AdminUser || !$admin->isEnabled()) {
            throw new InvalidStaffInput('Aucun compte actif ne correspond à cette adresse email.');
        }
        if ($current !== null && $current !== $admin && !$this->canRemoveOwner($current, $accounts)) {
            throw new InvalidStaffInput('Le dernier propriétaire ne peut pas être remplacé.');
        }
        if ($admin->getTeamRole() === TeamRole::Owner && $role !== TeamRole::Owner && !$this->canRemoveOwner($admin, $accounts)) {
            throw new InvalidStaffInput('Au moins un propriétaire actif est obligatoire.');
        }

        return function () use ($current, $admin, $member, $role): void {
            if ($current !== null && $current !== $admin) {
                $current->setStaffMember(null);
                // Release the unique staff_member_id before assigning it; both flushes
                // remain in the same transaction and roll back together on failure.
                $this->entityManager->flush();
            }
            $admin->setStaffMember($member);
            $admin->setTeamRole($role);
        };
    }

    /** @param list<AdminUser> $accounts */
    private function canRemoveOwner(AdminUser $admin, array $accounts): bool
    {
        if ($admin->getTeamRole() !== TeamRole::Owner) return true;

        return count(array_filter($accounts, static fn (AdminUser $account): bool =>
            $account->getTeamRole() === TeamRole::Owner && $account->isEnabled()
        )) > 1;
    }
}
