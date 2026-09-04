<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

final readonly class TenantDoctor implements TenantDoctorInterface
{
    public function __construct(
        private TenantRegistry $registry,
        private TenantContext $tenantContext,
        private Connection $connection,
        #[AutowireLocator('messenger.receiver')] private ServiceProviderInterface $messengerTransports,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function inspect(string $slug): array
    {
        $results = [];
        $tenant = $this->registry->get($slug);
        if ($tenant === null) {
            return [$this->result('ERROR', 'Registre', 'Tenant absent du registre.')];
        }

        $results[] = $this->result('OK', 'Registre', 'Tenant présent dans le registre.');
        if (!\is_string($tenant['db'] ?? null) || $tenant['db'] === '') {
            return [...$results, $this->result('ERROR', 'Connexion DB', 'Base de données non configurée.')];
        }
        if (($tenant['enabled'] ?? true) === false) {
            $results[] = $this->result('WARN', 'Registre', 'Tenant désactivé.');
        }

        $previousSlug = $this->tenantContext->getSlug();
        $this->tenantContext->setSlug($slug);
        $this->connection->close();

        try {
            try {
                $this->connection->fetchOne('SELECT 1');
                $results[] = $this->result('OK', 'Connexion DB', 'Connexion établie.');
            } catch (\Throwable) {
                return [...$results, $this->result('ERROR', 'Connexion DB', 'Connexion impossible.')];
            }

            $results[] = $this->checkMigrations();
            $results[] = $this->checkCount('Canal', 'sylius_channel', true, 'Aucun canal actif.');
            $results[] = $this->checkCount('Devise', 'sylius_currency', true, 'Aucune devise active.');
            $results[] = $this->checkCount('Locale', 'sylius_locale', true, 'Aucune locale active.');
            $results[] = $this->checkCount('Administrateur', 'sylius_admin_user', true, 'Aucun administrateur actif.');
            $results[] = $this->checkCount('Moyens de paiement', 'sylius_payment_method', true, 'Aucun moyen de paiement actif.');
            $results[] = $this->checkMessenger();
            $results[] = $this->checkDirectory('Médias', $this->projectDir.'/public/media/image/'.$slug);
            $results[] = $this->checkDirectory('Factures', $this->projectDir.'/private/invoices/'.$slug);
        } finally {
            $this->connection->close();
            $this->tenantContext->setSlug($previousSlug);
        }

        return $results;
    }

    /** @return array{status: 'OK'|'WARN'|'ERROR', check: string, detail: string} */
    private function checkMigrations(): array
    {
        try {
            $executed = array_map('strval', $this->connection->fetchFirstColumn('SELECT version FROM sylius_migrations'));
            $expected = array_map(
                static fn (string $file): string => 'DoctrineMigrations\\'.pathinfo($file, \PATHINFO_FILENAME),
                glob($this->projectDir.'/migrations/Version*.php') ?: [],
            );
            $pending = array_diff($expected, $executed);

            return $pending === []
                ? $this->result('OK', 'Migrations', sprintf('%d migration(s) appliquée(s).', \count($expected)))
                : $this->result('ERROR', 'Migrations', sprintf('%d migration(s) en attente.', \count($pending)));
        } catch (\Throwable) {
            return $this->result('ERROR', 'Migrations', 'État des migrations illisible.');
        }
    }

    /** @return array{status: 'OK'|'WARN'|'ERROR', check: string, detail: string} */
    private function checkCount(string $check, string $table, bool $blocking, string $emptyMessage): array
    {
        try {
            $count = (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE enabled = 1', $table));

            return $count > 0
                ? $this->result('OK', $check, sprintf('%d élément(s) actif(s).', $count))
                : $this->result($blocking ? 'ERROR' : 'WARN', $check, $emptyMessage);
        } catch (\Throwable) {
            return $this->result($blocking ? 'ERROR' : 'WARN', $check, 'Vérification impossible.');
        }
    }

    /** @return array{status: 'OK'|'WARN'|'ERROR', check: string, detail: string} */
    private function checkMessenger(): array
    {
        try {
            $ids = $this->messengerTransports->getProvidedServices();
            foreach (array_keys($ids) as $id) {
                $this->messengerTransports->get((string) $id);
            }

            return $ids === []
                ? $this->result('WARN', 'Transports Messenger', 'Aucun transport configuré.')
                : $this->result('OK', 'Transports Messenger', sprintf('%d transport(s) disponible(s).', \count($ids)));
        } catch (\Throwable) {
            return $this->result('ERROR', 'Transports Messenger', 'Un transport configuré est indisponible.');
        }
    }

    /** @return array{status: 'OK'|'WARN'|'ERROR', check: string, detail: string} */
    private function checkDirectory(string $check, string $directory): array
    {
        return is_dir($directory) && is_writable($directory)
            ? $this->result('OK', 'Répertoire '.$check, 'Répertoire accessible en écriture.')
            : $this->result('ERROR', 'Répertoire '.$check, 'Répertoire absent ou non inscriptible.');
    }

    /** @return array{status: 'OK'|'WARN'|'ERROR', check: string, detail: string} */
    private function result(string $status, string $check, string $detail): array
    {
        /** @var 'OK'|'WARN'|'ERROR' $status */
        return ['status' => $status, 'check' => $check, 'detail' => $detail];
    }
}
