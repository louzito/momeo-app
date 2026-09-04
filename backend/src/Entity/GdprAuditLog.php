<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_gdpr_audit_log')]
#[ORM\Index(name: 'idx_gdpr_audit_created', columns: ['created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_gdpr_audit_operation', columns: ['operation_id'])]
final class GdprAuditLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'operation_id', length: 64)] private string $operationId,
        #[ORM\Column(length: 30)] private string $action,
        #[ORM\Column(name: 'subject_hash', length: 64, nullable: true)] private ?string $subjectHash,
        #[ORM\Column(length: 180)] private string $actor,
        /** @var array<string, mixed> */
        #[ORM\Column(type: Types::JSON)] private array $details,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}
}
