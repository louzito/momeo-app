<?php

declare(strict_types=1);

namespace App\Tenant;

final readonly class ProvisionedTenant
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $databaseName,
        public ?string $adminEmail,
        public ?string $generatedPassword,
        public string $externalId,
        public int $remainingPool,
        public float $durationSeconds,
        public bool $alreadyExisting = false,
    ) {}
}
