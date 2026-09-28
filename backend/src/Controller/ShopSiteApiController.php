<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Site\{SiteManagementService, SiteLinkResolver};
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop/site')]
final class ShopSiteApiController
{
    public function __construct(private readonly SiteManagementService $management, private readonly SiteLinkResolver $links) {}
    #[Route('/pages/{slug}', methods: ['GET'])]
    public function page(string $slug): JsonResponse
    {
        $page = $this->management->publishedPage($slug);
        $published = $page?->getPublished();
        if ($published !== null) $published['media'] = $this->management->publicMedia($published);
        return new JsonResponse($published ?? ['error' => 'Page introuvable.'], $published === null ? 404 : 200, ['Cache-Control' => 'private, no-store']);
    }
    #[Route('/navigation', methods: ['GET'])]
    public function navigation(): JsonResponse
    {
        $result = ['main' => null, 'footer' => null, 'primary' => null];
        if ($this->management->isSitePublished()) {
            foreach (['main', 'footer'] as $location) {
                $menu = $this->management->menu($location);
                if ($menu?->getPublished() === null) continue;
                $result[$location] = $this->links->menu($menu->getPublished(), true);
                if ($location === 'main' && $menu->getPublishedPrimaryLink() !== null) {
                    $link = $menu->getPublishedPrimaryLink();
                    $url = $this->links->resolve($link, true);
                    if ($url !== null) $result['primary'] = ['url' => $url, 'external' => $link['type'] === 'external'];
                }
            }
        }
        return new JsonResponse($result, 200, ['Cache-Control' => 'private, no-store']);
    }
    #[Route('/menus/{location}', requirements: ['location' => 'main|footer'], methods: ['GET'])]
    public function menu(string $location): JsonResponse
    {
        $published = $this->management->menu($location)?->getPublished();
        return new JsonResponse($published === null ? ['error' => 'Menu introuvable.'] : ['items' => $this->links->menu($published, true)], $published === null ? 404 : 200, ['Cache-Control' => 'private, no-store']);
    }
}
