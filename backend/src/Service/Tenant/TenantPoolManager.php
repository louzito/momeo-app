<?php

declare(strict_types=1);

namespace App\Service\Tenant;

/** Clone first, register afterwards; preserves the existing pool allocation semantics. */
final readonly class TenantPoolManager
{
    public function __construct(private TenantRegistryWriter $writer, private TenantDatabaseCloner $cloner) {}

    /** @return array{slug: string, db: string, tables: int, duration: float}|null No template. */
    public function add(): ?array
    {
        $all = $this->writer->read();
        $templateDb = null;
        foreach ($all as $entry) {
            if (($entry['status'] ?? '') === 'template' && \is_string($entry['db'] ?? null)) {
                $templateDb = $entry['db'];
                break;
            }
        }
        if ($templateDb === null) {
            return null;
        }

        $next = 1;
        foreach (array_keys($all) as $slug) {
            if (preg_match('/^pool-(\d{3})$/', (string) $slug, $m)) {
                $next = max($next, ((int) $m[1]) + 1);
            }
        }
        $slug = sprintf('pool-%03d', $next);
        $db = 'skybook_pool_' . bin2hex(random_bytes(4));

        $started = microtime(true);
        $tables = $this->cloner->cloneDatabase($templateDb, $db);
        $this->writer->upsert($slug, ['db' => $db, 'name' => 'Centre ' . $slug, 'enabled' => true, 'status' => 'pool']);

        return ['slug' => $slug, 'db' => $db, 'tables' => $tables, 'duration' => microtime(true) - $started];
    }
}
