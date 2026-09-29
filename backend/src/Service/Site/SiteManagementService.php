<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\{SitePage, SiteMenu, SiteMenuItem};
use Doctrine\ORM\EntityManagerInterface;

final class SiteManagementService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SiteDocumentValidator $validator, private readonly SiteLinkResolver $links, private readonly SiteMediaReferences $media, private readonly ?SiteTemplateService $templates = null) {}

    public function publicMedia(array $document): array { return $this->media->publicMedia($document); }
    public function pages(): array { return $this->em->getRepository(SitePage::class)->findBy([], ['slug' => 'ASC']); }
    public function page(string $id): ?SitePage { return $this->em->find(SitePage::class, $id); }
    public function publishedPage(string $slug): ?SitePage
    {
        $page = $this->em->getRepository(SitePage::class)->findOneBy(['publishedSlug' => $slug, 'archived' => false]);
        if ($page) return $page;
        foreach ($this->pages() as $page) if (!$page->isArchived() && in_array($slug, $page->getPreviousSlugs(), true)) return $page;
        return null;
    }
    public function create(array $data): SitePage
    {
        $this->validator->keys($data, ['title', 'slug'], ['role', 'template']);
        $role = $data['role'] ?? null;
        if (!in_array($role, [null, 'home', 'terms', 'mentions'], true)) throw new \InvalidArgumentException('Rôle invalide.');
        if ($role !== null && $this->em->getRepository(SitePage::class)->findOneBy(['role' => $role])) throw new \InvalidArgumentException('Cette page système existe déjà.');
        if (isset($data['template']) && !is_string($data['template'])) throw new \InvalidArgumentException('Modèle invalide.');
        $document = isset($data['template']) ? ($this->templates ?? new SiteTemplateService($this->em))->document($data['template']) : ['schemaVersion' => 1, 'blocks' => []];
        $draft = $this->validator->page(['title' => $data['title'], 'slug' => $data['slug'], 'document' => $document, 'seo' => ['title' => '', 'description' => '']]);
        $this->uniqueSlug($draft['slug']);
        $this->links->validatePage($draft);
        $this->media->validate($draft);
        $this->validateCatalog($draft);
        $page = new SitePage($draft, $role);
        $this->em->persist($page);
        $this->media->sync($page);
        $this->em->flush();
        return $page;
    }
    public function update(SitePage $page, array $data): void
    {
        $this->validator->page($data);
        $this->links->validatePage($data);
        $this->media->validate($data);
        $this->validateCatalog($data);
        $this->uniqueSlug($data['slug'], $page);
        $page->revise($data);
        $this->media->sync($page);
        $this->em->flush();
    }
    public function duplicate(SitePage $page, array $data): SitePage
    {
        $this->validator->keys($data, ['title', 'slug']);
        $draft = $this->validator->page(array_replace($page->getDraft(), $data));
        $this->links->validatePage($draft);
        $this->media->validate($draft);
        $this->validateCatalog($draft);
        $this->uniqueSlug($draft['slug']);
        $copy = new SitePage($draft);
        $this->em->persist($copy);
        $this->media->sync($copy);
        $this->em->flush();
        return $copy;
    }
    public function archive(SitePage $page): void { $page->archive(); $this->em->flush(); }
    public function restore(SitePage $page): void
    {
        $snapshot = $page->getPublished() ?? $page->getLegacyPublished();
        if ($snapshot === null) throw new \InvalidArgumentException('Aucune version publiée à restaurer.');
        $this->update($page, $snapshot);
    }
    public function publish(SitePage $page): void { $this->publishBatch([$page->getId()], []); }

    public function rolePage(string $role): ?SitePage
    {
        return $this->em->getRepository(SitePage::class)->findOneBy(['role' => $role, 'archived' => false]);
    }
    /** The exact same visible document and references feed preview and public rendering. */
    public function render(SitePage $page, bool $preview = false): ?array
    {
        if ($page->isArchived()) return null;
        $document = $preview ? $page->getDraft() : $page->getPublished();
        if ($document === null) return null;
        $document['document']['blocks'] = array_values(array_filter($document['document']['blocks'], static fn (array $block): bool => !($block['hidden'] ?? false)));
        $document['media'] = $this->publicMedia($document);
        $document['links'] = [];
        foreach ($document['document']['blocks'] as $block) {
            $link = $block['props']['button']['link'] ?? $block['props']['link'] ?? null;
            if ($link !== null && ($url = $this->links->resolve($link, !$preview)) !== null) {
                if ($preview && $link['type'] === 'page') $url = 'admin/site/preview/'.$link['target'];
                $document['links'][json_encode($link, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] = ['url' => $url, 'external' => $link['type'] === 'external'];
            }
        }
        $document['role'] = $page->getRole();
        $document['media'] = (object) $document['media'];
        $document['links'] = (object) $document['links'];
        return $document;
    }
    /** The active Doctrine connection is the current establishment's database. */
    private function validateCatalog(array $document): void
    {
        foreach ($document['document']['blocks'] as $block) {
            if ($block['type'] === 'catalog') {
                foreach ($block['props']['codes'] as $code) {
                    $product = $this->em->getRepository(\App\Entity\Product\Product::class)->findOneBy(['code' => $code]);
                    if (!$product instanceof \App\Entity\Product\Product || !in_array($product->getTodatempoType(), ['service', 'physical'], true)) {
                        throw new \InvalidArgumentException('Une offre est introuvable dans cet établissement. Retirez-la de la sélection.');
                    }
                }
            }
        }
    }
    private function uniqueSlug(string $slug, ?SitePage $current = null): void
    {
        foreach ($this->pages() as $page) if ($page !== $current && in_array($slug, $page->getPreviousSlugs(), true)) throw new \InvalidArgumentException('Cette adresse est déjà utilisée.');
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
        $this->validator->keys($data, ['items'], ['primaryLink']);
        $items = $this->validator->menu($data['items']);
        $this->links->menu($items);
        return $this->em->wrapInTransaction(function () use ($location, $items, $data): SiteMenu {
            $menu = $this->menu($location) ?? new SiteMenu($location);
            if ($this->em->contains($menu)) {
                $this->em->lock($menu, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($menu);
            }
            $primary = array_key_exists('primaryLink', $data) ? $data['primaryLink'] : $menu->getPrimaryLink();
            if ($primary !== null) {
                if ($location !== 'main') throw new \InvalidArgumentException('Le bouton principal appartient au menu principal.');
                $this->links->resolve($this->validator->link($primary));
            }
            $menu->touchDraft();
            $menu->setPrimaryLink($primary);
            $this->em->persist($menu);
            foreach ($this->em->getRepository(SiteMenuItem::class)->findBy(['menu' => $menu]) as $item) $this->em->remove($item);
            foreach ($items as $position => $item) $this->em->persist(new SiteMenuItem($menu, $position, $item));
            return $menu;
        });
    }
    public function restoreMenu(string $location, int $revision): void
    {
        $this->em->wrapInTransaction(function () use ($location, $revision): void {
            $menu = $this->menu($location);
            if (!$menu) throw new \InvalidArgumentException('Menu introuvable.');
            $this->em->lock($menu, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($menu);
            if ($revision !== $menu->getRevision()) throw \Doctrine\ORM\OptimisticLockException::lockFailed($menu);
            if ($menu->getPublished() === null) throw new \InvalidArgumentException('Aucune version publiée à restaurer.');
            $this->saveMenu($location, ['items' => $menu->getPublished(), 'primaryLink' => $menu->getPublishedPrimaryLink()]);
        });
    }
    public function deleteMenu(string $location): void
    {
        $menu = $this->menu($location);
        if ($menu === null) return;
        $this->saveMenu($location, ['items' => [], 'primaryLink' => null]);
    }
    public function publishMenu(SiteMenu $menu): void
    {
        $this->publishBatch([], [$menu->getLocation()]);
    }
    /** Atomic publication contract used by the site publication step. IDs are resolved in this tenant. */
    public function publishBatch(array $pageIds, array $locations, array $revisions = []): void
    {
        $this->em->wrapInTransaction(function () use ($pageIds, $locations, $revisions): void {
            // Lock referenced pages too: their published address and archival state must remain
            // stable throughout validation and commit. Deterministic order avoids crossed batches.
            foreach ($this->em->getRepository(SitePage::class)->findBy([], ['id' => 'ASC']) as $page) {
                $this->em->lock($page, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($page);
            }
            $orderedLocations = array_unique($locations); sort($orderedLocations);
            foreach ($orderedLocations as $location) {
                if ($menu = $this->menu($location)) {
                    $this->em->lock($menu, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                    $this->em->refresh($menu);
                }
            }
            $pages = [];
            foreach (array_unique($pageIds) as $id) {
                $page = $this->page($id);
                if (!$page || $page->isArchived()) throw new \InvalidArgumentException('Page introuvable ou archivée.');
                if ($revisions && ($revisions[$id] ?? null) !== $page->getRevision()) throw \Doctrine\ORM\OptimisticLockException::lockFailed($page);
                $this->validator->page($page->getDraft());
                $this->media->validate($page->getDraft());
                $this->validateCatalog($page->getDraft());
                $this->uniqueSlug($page->getSlug(), $page);
                $this->links->validatePage($page->getDraft(), true, $pageIds);
                $pages[] = $page;
            }
            $menus = [];
            foreach (array_unique($locations) as $location) {
                $menu = $this->menu($location);
                if (!$menu) throw new \InvalidArgumentException('Menu introuvable.');
                if ($revisions && ($revisions[$location] ?? null) !== $menu->getRevision()) throw \Doctrine\ORM\OptimisticLockException::lockFailed($menu);
                $items = $this->validator->menu($this->menuItems($menu));
                $this->assertPublishedLinks($items, $pageIds);
                if ($menu->getPrimaryLink() !== null && $this->links->resolve($menu->getPrimaryLink(), true, $pageIds) === null) throw new \InvalidArgumentException('Publiez également la page du bouton principal.');
                $menus[] = [$menu, $items];
            }
            foreach ($pages as $page) { $page->publish(); $this->media->sync($page); }
            foreach ($menus as [$menu, $items]) $menu->publish($items);
        });
    }
    private function assertPublishedLinks(array $items, array $publishing = []): void
    {
        foreach ($items as $item) {
            if ($item['hidden'] ?? false) continue;
            if ($this->links->resolve($item['link'], true, $publishing) === null) throw new \InvalidArgumentException('Publiez également les pages du menu ou retirez les liens indisponibles.');
            $this->assertPublishedLinks($item['children'] ?? [], $publishing);
        }
    }
    public function previewNavigation(array $locations): array
    {
        if (array_diff($locations, ['main', 'footer'])) throw new \InvalidArgumentException('Menu invalide.');
        $result = [];
        foreach ($locations as $location) {
            $menu = $this->menu($location);
            if (!$menu) continue;
            $result[$location] = $this->links->menu($this->menuItems($menu), false, false, true);
            if ($location === 'main') {
                $result['primary'] = null;
                if ($link = $menu->getPrimaryLink()) {
                    $url = $link['type'] === 'page' ? 'admin/site/preview/'.$link['target'] : $this->links->resolve($link);
                    $result['primary'] = ['url' => $url, 'external' => $link['type'] === 'external'];
                }
            }
        }
        return $result;
    }
    public function isSitePublished(): bool
    {
        $home = $this->em->getRepository(SitePage::class)->findOneBy(['role' => 'home', 'archived' => false]);
        return $home?->getPublished() !== null;
    }
}
