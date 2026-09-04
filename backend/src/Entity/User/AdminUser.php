<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\StaffMember;
use App\Security\TeamRole;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\AdminUser as BaseAdminUser;
use Sylius\MolliePlugin\Entity\OnboardingStatusAwareInterface;
use Sylius\MolliePlugin\Entity\OnboardingStatusAwareTrait;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_admin_user')]
class AdminUser extends BaseAdminUser implements OnboardingStatusAwareInterface
{
    use OnboardingStatusAwareTrait;

    #[ORM\Column(name: 'team_role', length: 20, enumType: TeamRole::class)]
    private TeamRole $teamRole = TeamRole::Practitioner;

    #[ORM\OneToOne(targetEntity: StaffMember::class)]
    #[ORM\JoinColumn(name: 'staff_member_id', referencedColumnName: 'id', nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?StaffMember $staffMember = null;

    public function getTeamRole(): TeamRole { return $this->teamRole; }
    public function setTeamRole(TeamRole $teamRole): void { $this->teamRole = $teamRole; }
    public function getStaffMember(): ?StaffMember { return $this->staffMember; }
    public function setStaffMember(?StaffMember $staffMember): void { $this->staffMember = $staffMember; }
}
