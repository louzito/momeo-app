<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\SitePage;
use Doctrine\ORM\EntityManagerInterface;

final class SiteLinkResolver
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SiteDocumentValidator $validator) {}

    /** Internal paths are relative to the current tenant's frontend base. */
    public function resolve(array $link, bool $published = false, array $publishing = [], bool $allowArchived = false): ?string
    {
        $this->validator->link($link);
        if ($link['type'] === 'external') return $link['target'];
        if ($link['type'] === 'route') return SiteDocumentValidator::ROUTES[$link['target']];
        $page = $this->em->find(SitePage::class, $link['target']);
        if (!$page instanceof SitePage || ($page->isArchived() && !$allowArchived)) {
            if ($published) return null;
            throw new \InvalidArgumentException('La page liée est introuvable dans cet établissement.');
        }
        $document = $published && !in_array($page->getId(), $publishing, true) ? $page->getPublished() : $page->getDraft();
        return $document === null ? null : $document['slug'];
    }
    public function validatePage(array $page, bool $published = false, array $publishing = []): void
    {
        foreach ($page['document']['blocks'] as $block) {
            if ($block['type'] === 'button' && $this->resolve($block['props']['link'], $published, $publishing) === null) throw new \InvalidArgumentException('Publiez d’abord la page liée.');
        }
    }
    public function menu(array $items, bool $published = false, bool $hiddenParent = false): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($published && ($item['hidden'] ?? false)) continue;
            $hidden = $hiddenParent || ($item['hidden'] ?? false);
            $url = $this->resolve($item['link'], $published, [], !$published && $hidden);
            if ($url === null) continue;
            $result[] = ['label' => $item['label'], 'url' => $url, 'external' => $item['link']['type'] === 'external', 'children' => $this->menu($item['children'] ?? [], $published, $hidden)];
        }
        return $result;
    }
}
