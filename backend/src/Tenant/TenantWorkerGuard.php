<?php

declare(strict_types=1);

namespace App\Tenant;

/** Valide le tenant d'un worker avant que Messenger puisse ouvrir Doctrine. */
final readonly class TenantWorkerGuard
{
    public function __construct(
        private TenantContext $context,
        private TenantRegistry $registry,
    ) {
    }

    public function validate(): string
    {
        $slug = $this->context->getExplicitSlug();
        if ($slug === null) {
            throw new \RuntimeException(
                'Worker Messenger sans tenant. Definissez SKYBOOK_TENANT (ex. SKYBOOK_TENANT=skyline bin/console messenger:consume async) avant de le demarrer.',
            );
        }

        if ($this->registry->databaseFor($slug) === null) {
            throw new \RuntimeException(sprintf(
                'Worker Messenger : tenant "%s" inconnu ou sans base dans config/tenants.json. Verifiez avec "bin/console skybook:tenant:list".',
                $slug,
            ));
        }

        // Fige le tenant valide pour toute la duree de vie du worker.
        $this->context->setSlug($slug);

        return $slug;
    }
}
