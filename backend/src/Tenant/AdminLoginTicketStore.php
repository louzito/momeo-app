<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\User\AdminUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Creates short-lived, opaque, single-use admin login tickets. */
final class AdminLoginTicketStore
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantRegistry $tenantRegistry,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
    ) {}

    public function create(string $slug, string $email, string $name): string
    {
        $slug = strtolower(trim($slug));
        $email = mb_strtolower(trim($email));
        if (!$this->tenantRegistry->isServable($slug) || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Tenant ou administrateur invalide.');
        }

        $this->tenantContext->setSlug($slug);
        if ($this->connection->isConnected()) {
            $this->connection->close();
        }
        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if (!$admin instanceof AdminUser || !$admin->isEnabled()) {
            throw new \InvalidArgumentException('Administrateur introuvable.');
        }

        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $item = $this->cache->getItem($this->key($code));
        $item->set(['slug' => $slug, 'email' => $email, 'name' => trim($name)]);
        $item->expiresAfter(60);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('Impossible d’enregistrer le ticket de connexion.');
        }

        return $code;
    }

    /** @return array{slug: string, email: string, name: string} */
    public function consume(string $code): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $code)) {
            throw new \UnexpectedValueException('Ticket invalide.');
        }
        $key = $this->key($code);
        $item = $this->cache->getItem($key);
        $payload = $item->isHit() ? $item->get() : null;
        $this->cache->deleteItem($key);

        if (!\is_array($payload)
            || !\is_string($payload['slug'] ?? null)
            || !\is_string($payload['email'] ?? null)
            || !\is_string($payload['name'] ?? null)
            || !hash_equals($this->tenantContext->getSlug(), $payload['slug'])
        ) {
            throw new \UnexpectedValueException('Ticket invalide, expiré ou déjà utilisé.');
        }

        /** @var array{slug: string, email: string, name: string} $payload */
        return $payload;
    }

    /** @param array{slug: string, email: string, name: string} $ticket */
    public function createBrowserSession(array $ticket): string
    {
        $code = $this->randomCode();
        $item = $this->cache->getItem($this->browserSessionKey($code));
        $item->set($ticket);
        $item->expiresAfter(60);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('Impossible dâ€™enregistrer la session de connexion.');
        }

        return $code;
    }

    /** @return array{slug: string, email: string, name: string} */
    public function consumeBrowserSession(string $code): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $code)) {
            throw new \UnexpectedValueException('Session de connexion invalide.');
        }
        $key = $this->browserSessionKey($code);
        $item = $this->cache->getItem($key);
        $payload = $item->isHit() ? $item->get() : null;
        $this->cache->deleteItem($key);

        if (!\is_array($payload)
            || !\is_string($payload['slug'] ?? null)
            || !\is_string($payload['email'] ?? null)
            || !\is_string($payload['name'] ?? null)
            || !hash_equals($this->tenantContext->getSlug(), $payload['slug'])
        ) {
            throw new \UnexpectedValueException('Session de connexion invalide ou expirÃ©e.');
        }

        /** @var array{slug: string, email: string, name: string} $payload */
        return $payload;
    }

    private function key(string $code): string
    {
        return 'momeo.admin_login.'.hash('sha256', $code);
    }

    private function browserSessionKey(string $code): string
    {
        return 'momeo.admin_browser_session.'.hash('sha256', $code);
    }

    private function randomCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
