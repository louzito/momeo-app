<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Channel\Channel;
use App\Entity\Taxonomy\Taxon;
use App\Entity\User\AdminUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Claims a pre-installed Sylius database for a TodaTempo workspace.
 *
 * The claim is serialized with a filesystem lock so two simultaneous sign-ups
 * cannot configure the same pool database. The external id makes retries
 * idempotent without exposing database names to the caller.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantRegistryWriter $writer,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function claim(string $slug, string $name, ?string $email, string $externalId): ProvisionedTenant
    {
        $slug = strtolower(trim($slug));
        $name = trim($name);
        $email = $email !== null ? mb_strtolower(trim($email)) : null;
        $externalId = trim($externalId);

        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
            throw new \InvalidArgumentException('Slug invalide.');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('Le nom de l’établissement est obligatoire.');
        }
        if ($externalId === '') {
            throw new \InvalidArgumentException('L’identifiant externe est obligatoire.');
        }
        if ($email !== null && $email !== '' && filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Adresse email invalide.');
        }

        return $this->withClaimLock(function () use ($slug, $name, $email, $externalId): ProvisionedTenant {
            $all = $this->writer->read();
            $existing = $all[$slug] ?? null;
            if (\is_array($existing)) {
                if (($existing['status'] ?? null) === 'active' && ($existing['externalId'] ?? null) === $externalId) {
                    return new ProvisionedTenant(
                        $slug,
                        (string) ($existing['name'] ?? $name),
                        (string) ($existing['db'] ?? ''),
                        $email,
                        null,
                        $externalId,
                        $this->remainingPool($all),
                        0.0,
                        true,
                    );
                }

                throw new \DomainException(sprintf('Le slug "%s" est déjà attribué.', $slug));
            }

            ksort($all);
            $poolSlug = null;
            foreach ($all as $candidateSlug => $entry) {
                if (($entry['status'] ?? '') === 'pool' && ($entry['enabled'] ?? true) !== false) {
                    $poolSlug = (string) $candidateSlug;
                    break;
                }
            }
            if ($poolSlug === null) {
                throw new \UnderflowException('Le pool d’instances Sylius est vide.');
            }

            $started = microtime(true);
            $databaseName = (string) ($all[$poolSlug]['db'] ?? '');
            $this->tenantContext->setSlug($poolSlug);
            if ($this->connection->isConnected()) {
                $this->connection->close();
            }

            $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
            if (!$channel instanceof Channel) {
                throw new \RuntimeException('La base modèle ne contient aucun canal Sylius.');
            }
            $channel->setName($name);
            if ($email !== null && $email !== '') {
                $channel->setContactEmail($email);
            }

            // Transitional storage: this taxon will be renamed to momeo_config
            // when the parachuting vocabulary is removed from the tenant template.
            $taxon = $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config']);
            if ($taxon instanceof Taxon) {
                $translation = $taxon->getTranslation('en_US');
                $config = json_decode($translation->getDescription() ?: '{}', true);
                $config = \is_array($config) ? $config : [];
                $config['name'] = $name;
                if ($email !== null && $email !== '') {
                    $config['contactEmail'] = $email;
                }
                $translation->setDescription((string) json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }

            $password = null;
            if ($email !== null && $email !== '') {
                $admin = $this->em->getRepository(AdminUser::class)->findOneBy([]);
                if ($admin instanceof AdminUser) {
                    $password = substr(rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='), 0, 20);
                    $admin->setEmail($email);
                    $admin->setUsername($email);
                    $admin->setPlainPassword($password);
                    $admin->setEnabled(true);
                }
            }

            $this->em->flush();
            @mkdir($this->projectDir.'/public/media/image/'.$slug, 0775, true);
            @mkdir($this->projectDir.'/private/invoices/'.$slug, 0775, true);

            $this->writer->rename($poolSlug, $slug, [
                'name' => $name,
                'status' => 'active',
                'enabled' => true,
                'externalId' => $externalId,
            ]);
            $updated = $this->writer->read();

            return new ProvisionedTenant(
                $slug,
                $name,
                $databaseName,
                $email,
                $password,
                $externalId,
                $this->remainingPool($updated),
                microtime(true) - $started,
            );
        });
    }

    /** @param array<string, array<string, mixed>> $tenants */
    private function remainingPool(array $tenants): int
    {
        return \count(array_filter($tenants, static fn (array $tenant): bool => ($tenant['status'] ?? '') === 'pool'));
    }

    /** @template T @param callable(): T $operation @return T */
    private function withClaimLock(callable $operation): mixed
    {
        $lockFile = $this->projectDir.'/var/momeo-tenant-claim.lock';
        $handle = fopen($lockFile, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d’ouvrir le verrou de provisionnement.');
        }

        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Impossible d’acquérir le verrou de provisionnement.');
            }

            return $operation();
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }
}
