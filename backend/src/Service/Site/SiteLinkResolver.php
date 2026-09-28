<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\SitePage;
use Doctrine\ORM\EntityManagerInterface;

final class SiteLinkResolver
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SiteDocumentValidator $validator) {}

    /** Internal paths are relative to the current tenant's frontend base. */
    public function resolve(array $link, bool $published = false): ?string
    {
        $this->validator->link($link);
        if ($link['type'] === 'external') return $link['target'];
        if ($link['type'] === 'route') return SiteDocumentValidator::ROUTES[$link['target']];
        $page = $this->em->find(SitePage::class, $link['target']);
        if (!$page instanceof SitePage || $page->isArchived()) {
            if ($published) return null;
            throw new \InvalidArgumentException('La page liée est introuvable dans cet établissement.');
        }
        $document = $published ? $page->getPublished() : $page->getDraft();
        return $document === null ? null : $document['slug'];
    }
    public function validatePage(array $page, bool $published = false): void
    {
        foreach ($page['document']['blocks'] as $block) {
            if ($block['type'] === 'button' && $this->resolve($block['props']['link'], $published) === null) throw new \InvalidArgumentException('Publiez d’abord la page liée.');
        }
    }
    public function menu(array $items, bool $published = false): array
    {
        $result = [];
        foreach ($items as $item) {
            $url = $this->resolve($item['link'], $published);
            if ($url === null) continue;
            $result[] = ['label' => $item['label'], 'url' => $url, 'external' => $item['link']['type'] === 'external', 'children' => $this->menu($item['children'] ?? [], $published)];
        }
        return $result;
    }
}
