<?php

declare(strict_types=1);

namespace App\Service\Site;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use App\Service\Tenant\{TenantContext, TenantUrlGenerator};

final class SiteSeoService
{
    public function __construct(private readonly TenantContext $tenant, private readonly TenantUrlGenerator $urls, #[Autowire('%env(TODATEMPO_SITE_MEDIA_PATH)%')] private readonly string $mediaPath = '') {}

    public static function path(array $page): string
    {
        return match ($page['role'] ?? null) {
            'home' => '', 'terms' => 'legal/terms', 'mentions' => 'legal/mentions',
            default => $page['slug'],
        };
    }

    public function base(): string { return $this->urls->url($this->tenant->getSlug()); }

    public function mediaUrl(string $path): string
    {
        $base = preg_replace('#/[^/]+/$#', '/', $this->base());
        if ($this->mediaPath !== '') {
            if (!preg_match('#^/(?:[a-zA-Z0-9_-]+/?)*$#D', $this->mediaPath)) throw new \RuntimeException('Invalid media prefix.');
            $parts = parse_url($base);
            $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$this->mediaPath;
        }
        return rtrim($base, '/').$path;
    }

    public function metadata(array $page): array
    {
        $texts = [];
        $collect = function (array $value) use (&$collect, &$texts): void {
            foreach ($value as $key => $child) {
                if (is_array($child)) $collect($child);
                elseif (in_array($key, ['text', 'title', 'question', 'address', 'hours'], true) && is_string($child)) $texts[] = $child;
            }
        };
        foreach ($page['document']['blocks'] as $block) {
            if (!($block['hidden'] ?? false) && !in_array($block['type'], ['catalog', 'giftCard'], true)) $collect($block['props']);
        }
        $description = trim($page['seo']['description']) ?: mb_substr(trim(preg_replace('/\s+/u', ' ', implode(' ', $texts))), 0, 320);
        $imageId = $page['seo']['imageId'] ?? null;
        if (!$imageId) {
            $editorial = $page;
            $editorial['document']['blocks'] = array_values(array_filter($page['document']['blocks'], static fn ($b) => !($b['hidden'] ?? false) && !in_array($b['type'], ['catalog', 'giftCard'], true)));
            $imageId = SiteMediaReferences::ids($editorial)[0] ?? null;
        }
        $media = (array) $page['media'];
        $path = $imageId !== null ? ($media[$imageId]['path'] ?? null) : null;
        $base = $this->base();
        // Images originate in this tenant's media repository, never from an input URL/Host.
        return [
            'title' => trim($page['seo']['title']) ?: $page['title'],
            'description' => $description ?: $page['title'],
            'canonical' => $base.self::path($page),
            'image' => $path ? $this->mediaUrl($path) : null,
        ];
    }
}
