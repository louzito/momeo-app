<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Bascule Doctrine par tenant : UNE seule connexion DBAL configuree
 * (DATABASE_URL), mais le nom de la base est resolu AU MOMENT DU connect()
 * selon le TenantContext. PHP-FPM = un process par requete, et le listener
 * kernel.request (priorite 512) pose le slug avant tout acces BDD.
 * (Prerequis : `server_version` statique dans doctrine.yaml, sinon DBAL se
 * connecte des le chargement des metadata, AVANT le listener — vu en vrai.)
 *
 * Tague automatiquement `doctrine.middleware` (autoconfiguration DoctrineBundle).
 *
 * NB : on ne touche pas aux connexions SANS dbname (ex. la connexion temporaire
 * de doctrine:database:create) — elles servent justement a creer des bases.
 */
final class TenantConnectionMiddleware implements Middleware
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->tenantContext) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly TenantContext $tenantContext)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                if (isset($params['dbname'])) {
                    $params['dbname'] = $this->tenantContext->getDatabaseName();
                }

                return parent::connect($params);
            }
        };
    }
}
