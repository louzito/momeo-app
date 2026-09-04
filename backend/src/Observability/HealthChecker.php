<?php

declare(strict_types=1);

namespace App\Observability;

use App\Tenant\TenantContext;
use App\Tenant\TenantRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HealthChecker
{
    /** @var list<string> */
    private array $dependencies;

    public function __construct(
        private TenantRegistry $registry,
        private TenantContext $tenantContext,
        private Connection $connection,
        private HttpClientInterface $httpClient,
        string $requiredDependencies,
    ) {
        $this->dependencies = array_values(array_filter(array_map('trim', explode(',', $requiredDependencies))));
    }

    /** @return array<string, bool> */
    public function applicationReadiness(): array
    {
        try {
            $tenants = $this->registry->all();
            $registryReady = $tenants !== [] && $this->registry->databaseFor($this->tenantContext->getDefaultSlug()) !== null;
        } catch (\Throwable) {
            $registryReady = false;
        }

        return ['tenant_registry' => $registryReady];
    }

    /** @return array<string, bool> */
    public function tenantReadiness(string $tenant): array
    {
        if (!$this->registry->isServable($tenant) || $this->registry->databaseFor($tenant) === null) {
            return ['tenant' => false, 'database' => false, 'dependencies' => false];
        }

        if ($this->connection->isConnected()) {
            $this->connection->close();
        }
        $this->tenantContext->setSlug($tenant);
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $databaseReady = true;
        } catch (\Throwable) {
            $databaseReady = false;
        }

        $dependenciesReady = true;
        foreach ($this->dependencies as $url) {
            try {
                $status = $this->httpClient->request('GET', $url, ['timeout' => 2.0])->getStatusCode();
                $dependenciesReady = $dependenciesReady && $status >= 200 && $status < 400;
            } catch (\Throwable) {
                $dependenciesReady = false;
            }
        }

        return ['tenant' => true, 'database' => $databaseReady, 'dependencies' => $dependenciesReady];
    }
}
