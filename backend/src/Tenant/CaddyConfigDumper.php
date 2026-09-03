<?php

declare(strict_types=1);

namespace App\Tenant;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Genere caddy/Caddyfile depuis le registre des tenants : c'est le reverse
 * proxy (port 80) qui fait le decoupage multi-centres cote back —
 *   localhost/{slug}/api/*  -> Sylius (nginx) avec X-Skybook-Tenant: {slug}
 *                              (le prefixe est retire : Sylius ne le voit jamais)
 *   localhost/{slug}/*      -> front Vue (Vite en dev, build en prod)
 *   slug inconnu            -> 404 propre au proxy
 *   /admin, /_profiler, /api, /media, /assets -> Sylius (tenant par defaut)
 *
 * Appele automatiquement par TenantRegistryWriter apres CHAQUE ecriture du
 * registre (claim, pool-add, remove…) ; caddy tourne avec `--watch` et se
 * recharge donc tout seul — aucun `docker compose exec` necessaire.
 * Upstreams surchargeables par env : SKYBOOK_PROXY_FRONT (defaut
 * host.docker.internal:5173 = Vite dev) et SKYBOOK_PROXY_SYLIUS (nginx:80).
 */
final class CaddyConfigDumper
{
    public function __construct(
        private readonly TenantRegistry $registry,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%skybook.default_tenant%')] private readonly string $defaultTenant,
    ) {
    }

    public function dump(): string
    {
        $front = $_SERVER['SKYBOOK_PROXY_FRONT'] ?? 'host.docker.internal:5173';
        $sylius = $_SERVER['SKYBOOK_PROXY_SYLIUS'] ?? 'nginx:80';

        $slugs = array_values(array_filter(
            array_keys($this->registry->all()),
            fn (string $slug): bool => $this->registry->isServable($slug),
        ));
        sort($slugs);

        $out = [];
        $out[] = '# ============================================================';
        $out[] = '# GÉNÉRÉ PAR TodaTempo (App\\Tenant\\CaddyConfigDumper) — NE PAS ÉDITER.';
        $out[] = '# Regenerer : bin/console skybook:proxy:dump (auto a chaque claim/refill).';
        $out[] = '# Caddy tourne avec --watch : toute regeneration est rechargee seule.';
        $out[] = '# ============================================================';
        $out[] = '{';
        $out[] = "\tadmin off";
        $out[] = "\tauto_https off";
        $out[] = '}';
        $out[] = '';
        $out[] = ':80 {';
        $out[] = "\t# Racine -> centre par defaut";
        $out[] = "\tredir / /{$this->defaultTenant}/ 302";
        $out[] = '';
        $out[] = "\t# Panel Sylius, profiler, API legacy (tenant par defaut), medias, assets Sylius";
        $out[] = "\t@sylius path /admin* /_profiler* /_wdt* /api/* /media/* /assets/* /build/* /bundles/* /payment-methods/*";
        $out[] = "\thandle @sylius {";
        $out[] = "\t\treverse_proxy {$sylius}";
        $out[] = "\t}";
        $out[] = '';

        foreach ($slugs as $slug) {
            $id = str_replace('-', '_', $slug);
            $out[] = "\t# --- centre : {$slug}";
            $out[] = "\t@{$id}_api path_regexp {$id}api ^/{$slug}/api(/.*)?$";
            $out[] = "\thandle @{$id}_api {";
            $out[] = "\t\turi strip_prefix /{$slug}";
            $out[] = "\t\treverse_proxy {$sylius} {";
            $out[] = "\t\t\theader_up X-Skybook-Tenant \"{$slug}\"";
            $out[] = "\t\t}";
            $out[] = "\t}";
            $out[] = "\tredir /{$slug} /{$slug}/ 302";
            $out[] = "\thandle /{$slug}/* {";
            $out[] = "\t\treverse_proxy {$front}";
            $out[] = "\t}";
            $out[] = '';
        }

        $out[] = "\t# Assets du dev server Vite (chemins absolus sans slug) + endpoint ops dev";
        $out[] = "\t@vite path /src/* /@vite/* /@id/* /@fs/* /@react-refresh /node_modules/* /favicon* /vite.svg /__skybook_ops*";
        $out[] = "\thandle @vite {";
        $out[] = "\t\treverse_proxy {$front}";
        $out[] = "\t}";
        $out[] = '';
        $out[] = "\t# Tout le reste : centre inconnu -> 404 propre";
        $out[] = "\thandle {";
        $out[] = "\t\trespond \"Centre inconnu.\" 404";
        $out[] = "\t}";
        $out[] = '}';

        $dir = $this->projectDir . '/caddy';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/Caddyfile';
        $tmp = $file . '.tmp';
        file_put_contents($tmp, implode("\n", $out) . "\n");
        rename($tmp, $file);

        return $file;
    }
}
