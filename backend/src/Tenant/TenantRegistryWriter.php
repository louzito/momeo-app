<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ecritures du registre config/tenants.json (upsert / rename / remove).
 * Ecriture atomique (fichier temporaire + rename). Le rename de slug est
 * l'operation cle du claim : la BDD ne bouge pas, seul le registre change.
 * Chaque ecriture regenere aussi caddy/Caddyfile (proxy multi-centres) —
 * caddy tourne avec --watch et se recharge tout seul.
 */
final class TenantRegistryWriter
{
    public function __construct(
        #[Autowire('%todatempo.tenants_file%')] private readonly string $file,
        private readonly CaddyConfigDumper $caddyDumper,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    public function read(): array
    {
        $data = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : [];

        return \is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $entry */
    public function upsert(string $slug, array $entry): void
    {
        $all = $this->read();
        $all[$slug] = array_merge($all[$slug] ?? [], $entry);
        $this->write($all);
    }

    /** @param array<string, mixed> $overrides */
    public function rename(string $from, string $to, array $overrides = []): void
    {
        $all = $this->read();
        if (!isset($all[$from])) {
            throw new \RuntimeException(sprintf('Tenant "%s" absent du registre.', $from));
        }
        if (isset($all[$to])) {
            throw new \RuntimeException(sprintf('Le slug "%s" est deja pris.', $to));
        }
        $entry = array_merge($all[$from], $overrides);
        unset($all[$from]);
        $all[$to] = $entry;
        $this->write($all);
    }

    public function remove(string $slug): void
    {
        $all = $this->read();
        unset($all[$slug]);
        $this->write($all);
    }

    public function assignCustomDomain(string $slug, string $host, string $token): void
    {
        $lock = fopen($this->file.'.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Impossible de verrouiller le registre des tenants.');
        }
        try {
            $all = $this->read();
            if (!isset($all[$slug])) {
                throw new \InvalidArgumentException('Tenant inconnu.');
            }
            foreach ($all as $owner => $entry) {
                if ($owner !== $slug && strtolower((string) ($entry['customDomain']['host'] ?? '')) === $host) {
                    throw new \DomainException('Ce domaine est déjà réclamé par un autre tenant.');
                }
            }
            $all[$slug]['customDomain'] = ['host' => $host, 'verificationToken' => $token, 'verifiedAt' => null];
            $this->write($all);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function markCustomDomainVerified(string $slug, string $host, string $token): void
    {
        $lock = fopen($this->file.'.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Impossible de verrouiller le registre des tenants.');
        }
        try {
            $all = $this->read();
            if (($all[$slug]['customDomain']['host'] ?? null) !== $host
                || ($all[$slug]['customDomain']['verificationToken'] ?? null) !== $token) {
                throw new \RuntimeException('La demande de domaine a changé pendant sa validation.');
            }
            $all[$slug]['customDomain']['verifiedAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
            $this->write($all);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, array<string, mixed>> $all */
    private function write(array $all): void
    {
        ksort($all);
        $json = json_encode($all, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $tmp = $this->file . '.tmp';
        file_put_contents($tmp, $json . "\n");
        rename($tmp, $this->file);

        // Le proxy suit le registre. Jamais bloquant (ex. caddy/ non inscriptible).
        try {
            $this->caddyDumper->dump();
        } catch (\Throwable) {
            // best effort
        }
    }
}
