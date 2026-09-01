<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StaffTimeOffRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StaffTimeOffRepository::class)]
#[ORM\Table(name: 'momeo_staff_time_off')]
#[ORM\Index(name: 'idx_momeo_time_off_start', columns: ['starts_at'])]
#[ORM\Index(name: 'idx_momeo_time_off_staff', columns: ['staff_member_id'])]
class StaffTimeOff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: StaffMember::class)]
    #[ORM\JoinColumn(name: 'staff_member_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StaffMember $staffMember;

    #[ORM\Column(name: 'starts_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(name: 'ends_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(length: 255)]
    private string $reason = 'Indisponible';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getStaffMember(): StaffMember { return $this->staffMember; }
    public function setStaffMember(StaffMember $value): void { $this->staffMember = $value; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $value): void { $this->startsAt = $value; }
    public function getEndsAt(): \DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(\DateTimeImmutable $value): void { $this->endsAt = $value; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $value): void { $this->reason = $value; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
