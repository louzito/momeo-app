<?php

declare(strict_types=1);

namespace App\Tenant;

class DomainOwnershipVerifier
{
    public const TXT_PREFIX = '_todatempo-verification.';

    public function isVerified(string $domain, string $token): bool
    {
        $records = dns_get_record(self::TXT_PREFIX.$domain, DNS_TXT);
        if (!\is_array($records)) {
            return false;
        }
        foreach ($records as $record) {
            $value = $record['txt'] ?? null;
            if (\is_string($value) && hash_equals('todatempo='.$token, trim($value))) {
                return true;
            }
        }

        return false;
    }
}
