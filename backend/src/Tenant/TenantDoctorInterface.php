<?php

declare(strict_types=1);

namespace App\Tenant;

interface TenantDoctorInterface
{
    /** @return list<array{status: 'OK'|'WARN'|'ERROR', check: string, detail: string}> */
    public function inspect(string $slug): array;
}
