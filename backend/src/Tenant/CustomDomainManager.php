<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CustomDomainManager
{
    public function __construct(
        private readonly TenantRegistry $registry,
        private readonly TenantRegistryWriter $writer,
        private readonly DomainOwnershipVerifier $verifier,
        #[Autowire('%todatempo.public_base_url%')] private readonly string $fallbackBaseUrl,
    ) {
    }

    /** @return array{domain: string, record: string, value: string} */
    public function request(string $slug, string $domain): array
    {
        if ($this->registry->get($slug) === null) {
            throw new \InvalidArgumentException('Tenant inconnu.');
        }
        $domain = DomainName::normalize($domain);
        $fallbackHost = parse_url($this->fallbackBaseUrl, PHP_URL_HOST);
        if (\is_string($fallbackHost) && strtolower($fallbackHost) === $domain) {
            throw new \DomainException('Le domaine principal TodaTempo est réservé.');
        }
        $token = bin2hex(random_bytes(24));
        $this->writer->assignCustomDomain($slug, $domain, $token);

        return ['domain' => $domain, 'record' => DomainOwnershipVerifier::TXT_PREFIX.$domain, 'value' => 'todatempo='.$token];
    }

    public function verify(string $slug): bool
    {
        $claim = $this->registry->get($slug)['customDomain'] ?? null;
        if (!\is_array($claim) || !\is_string($claim['host'] ?? null) || !\is_string($claim['verificationToken'] ?? null)) {
            throw new \InvalidArgumentException('Aucune demande de domaine pour ce tenant.');
        }
        if (!$this->verifier->isVerified($claim['host'], $claim['verificationToken'])) {
            return false;
        }
        $this->writer->markCustomDomainVerified($slug, $claim['host'], $claim['verificationToken']);

        return true;
    }
}
