<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\{SitePage, SiteMenu, SiteMenuItem};
use Doctrine\ORM\EntityManagerInterface;

final class SiteManagementService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SiteDocumentValidator $validator, private readonly SiteLinkResolver $links) {}

    public function pages(): array { return $this->em->getRepository(SitePage::class)->findBy([], ['slug' => 'ASC']); }
    public function page(string $id): ?SitePage { return $this->em->find(SitePage::class, $id); }
    public function publishedPage(string $slug): ?SitePage { return $this->em->getRepository(SitePage::class)->findOneBy(['publishedSlug' => $slug, 'archived' => false]); }
    public function create(array $data): SitePage
    {
        $this->validator->keys($data, ['title', 'slug'], ['role']);
        $role = $data['role'] ?? null;
        if (!in_array($role, [null, 'home', 'terms', 'mentions'], true)) throw new \InvalidArgumentException('Rôle invalide.');
        if ($role !== null && $this->em->getRepository(SitePage::class)->findOneBy(['role' => $role])) throw new \InvalidArgumentException('Cette page système existe déjà.');
        $draft = $this->validator->page(['title' => $data['title'], 'slug' => $data['slug'], 'document' => ['schemaVersion' => 1, 'blocks' => []], 'seo' => ['title' => '', 'description' => '']]);
        $this->uniqueSlug($draft['slug']);
        $page = new SitePage($draft, $role);
        $this->em->persist($page);
        $this->em->flush();
        return $page;
    }
    public function update(SitePage $page, array $data): void
    {
        $this->validator->page($data);
        $this->links->validatePage($data);
        $this->uniqueSlug($data['slug'], $page);
        $page->revise($data);
        $this->em->flush();
    }
    public function duplicate(SitePage $page, array $data): SitePage
    {
        $this->validator->keys($data, ['title', 'slug']);
        $draft = $this->validator->page(array_replace($page->getDraft(), $data));
        $this->links->validatePage($draft);
        $this->uniqueSlug($draft['slug']);
        $copy = new SitePage($draft);
        $this->em->persist($copy);
        $this->em->flush();
        return $copy;
    }
    public function archive(SitePage $page): void { $page->archive(); $this->em->flush(); }
    public function restore(SitePage $page): void
    {
        $snapshot = $page->getPublished();
        if ($snapshot === null) throw new \InvalidArgumentException('Aucune version publiée à restaurer.');
        $this->update($page, $snapshot);
    }
    /** Publication contract for subsequent steps; intentionally no HTTP publish endpoint yet. */
    public function publish(SitePage $page): void
    {
        $this->em->wrapInTransaction(function () use ($page): void {
            $this->validator->page($page->getDraft());
            $this->links->validatePage($page->getDraft(), true);
            $this->uniqueSlug($page->getSlug(), $page);
            $page->publish();
        });
    }
    private function uniqueSlug(string $slug, ?SitePage $current = null): void
    {
        foreach (['slug', 'publishedSlug'] as $field) {
            $other = $this->em->getRepository(SitePage::class)->findOneBy([$field => $slug]);
            if ($other !== null && $other !== $current) throw new \InvalidArgumentException('Cette adresse est déjà utilisée.');
        }
    }
    public function menu(string $location): ?SiteMenu
    {
        new SiteMenu($location); // Validate even if the menu does not exist yet.
        return $this->em->find(SiteMenu::class, $location);
    }
    public function menuItems(SiteMenu $menu): array
    {
        return array_map(static fn (SiteMenuItem $item): array => $item->getDocument(), $this->em->getRepository(SiteMenuItem::class)->findBy(['menu' => $menu], ['position' => 'ASC']));
    }
    public function saveMenu(string $location, array $data): SiteMenu
    {
        $this->validator->keys($data, ['items']);
        $items = $this->validator->menu($data['items']);
        $this->links->menu($items); // All references must belong to this tenant.
        return $this->em->wrapInTransaction(function () use ($location, $items): SiteMenu {
            $menu = $this->menu($location) ?? new SiteMenu($location);
            $this->em->persist($menu);
            foreach ($this->em->getRepository(SiteMenuItem::class)->findBy(['menu' => $menu]) as $item) $this->em->remove($item);
            foreach ($items as $position => $item) $this->em->persist(new SiteMenuItem($menu, $position, $item));
            return $menu;
        });
    }
    public function deleteMenu(string $location): void
    {
        $menu = $this->menu($location);
        if ($menu === null) return;
        $this->em->wrapInTransaction(function () use ($menu): void { $this->em->remove($menu); });
    }
    public function publishMenu(SiteMenu $menu): void
    {
        $this->em->wrapInTransaction(function () use ($menu): void {
            $items = $this->validator->menu($this->menuItems($menu));
            $this->assertPublishedLinks($items);
            $menu->publish($items);
        });
    }
    private function assertPublishedLinks(array $items): void
    {
        foreach ($items as $item) {
            if ($this->links->resolve($item['link'], true) === null) throw new \InvalidArgumentException('Publiez d’abord les pages du menu.');
            $this->assertPublishedLinks($item['children'] ?? []);
        }
    }
}
