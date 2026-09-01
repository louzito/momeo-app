<?php

declare(strict_types=1);

namespace App\Tenant;

use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * Isolation du cache applicatif par tenant : decore `cache.app` en prefixant
 * chaque cle par le slug courant (resolu A CHAQUE appel, donc aucun probleme
 * d'ordre d'instanciation). Les pools derives de cache.app (ex. le result
 * cache Doctrine en prod) heritent du prefixe. Le cache systeme (metadata,
 * config…) reste volontairement partage : meme code, meme schema.
 */
#[AsDecorator('cache.app')]
final class TenantAwareCachePool implements AdapterInterface, CacheInterface, NamespacedPoolInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly AdapterInterface $inner,
        private readonly TenantContext $tenantContext,
    ) {
    }

    private function prefix(): string
    {
        return 'sb.' . preg_replace('/[^A-Za-z0-9_.-]/', '', $this->tenantContext->getSlug()) . '.';
    }

    private function k(string $key): string
    {
        return $this->prefix() . $key;
    }

    public function getItem(mixed $key): \Symfony\Component\Cache\CacheItem
    {
        return $this->inner->getItem($this->k($key));
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->inner->getItems(array_map(fn (string $k) => $this->k($k), $keys));
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($this->k($key));
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->inner->clear($this->prefix() . $prefix);
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($this->k($key));
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems(array_map(fn (string $k) => $this->k($k), $keys));
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (!$this->inner instanceof CacheInterface) {
            throw new \LogicException('Inner cache pool does not implement CacheInterface.');
        }

        return $this->inner->get($this->k($key), fn (ItemInterface $item, bool &$save) => $callback($item, $save), $beta, $metadata);
    }

    public function delete(string $key): bool
    {
        if (!$this->inner instanceof CacheInterface) {
            throw new \LogicException('Inner cache pool does not implement CacheInterface.');
        }

        return $this->inner->delete($this->k($key));
    }

    public function withSubNamespace(string $namespace): static
    {
        if (!$this->inner instanceof NamespacedPoolInterface) {
            throw new \LogicException('Inner cache pool does not implement NamespacedPoolInterface.');
        }

        $pool = $this->inner->withSubNamespace($namespace);
        if (!$pool instanceof AdapterInterface) {
            throw new \LogicException('Namespaced inner cache pool does not implement AdapterInterface.');
        }

        return new self($pool, $this->tenantContext);
    }
}
