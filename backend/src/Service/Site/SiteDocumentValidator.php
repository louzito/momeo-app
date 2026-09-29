<?php

declare(strict_types=1);

namespace App\Service\Site;

/** Closed, versioned vocabulary. Extend explicitly when introducing new sections. */
final class SiteDocumentValidator
{
    public const ROUTES = ['home' => '', 'shop' => 'shop', 'products' => 'products', 'services' => 'shop?categorie=prestations', 'store' => 'shop?categorie=produits', 'booking' => 'shop?categorie=prestations', 'account' => 'account', 'gift-card' => 'gift-card', 'terms' => 'legal/terms', 'mentions' => 'legal/mentions'];
    public const RESERVED = ['accueil', 'services', 'products', 'jump', 'calendar', 'waitlist', 'gift-card', 'checkout', 't', 'beneficiary', 'account', 'boarding-pass', 'admin', 'status', 'shop', 'legal', 'api', 'media', 'assets', 'login', 'logout', 'payment', 'reservation', 'robots', 'sitemap'];

    public function keys(array $value, array $required, array $optional = []): void
    {
        if (array_diff($required, array_keys($value)) || array_diff(array_keys($value), [...$required, ...$optional])) $this->invalid();
    }
    public function text(mixed $value, int $max = 255, bool $empty = false): string
    {
        if (!is_string($value) || (!$empty && trim($value) === '') || mb_strlen($value) > $max || preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value)) $this->invalid();
        return $value;
    }
    public function slug(mixed $slug): string
    {
        if (!is_string($slug) || strlen($slug) > 120 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || in_array($slug, self::RESERVED, true)) throw new \InvalidArgumentException('Cette adresse est invalide ou réservée.');
        return $slug;
    }
    public function page(array $page): array
    {
        $this->keys($page, ['title', 'slug', 'document', 'seo']);
        $this->text($page['title'], 160);
        $this->slug($page['slug']);
        if (!is_array($page['seo']) || !is_array($page['document'])) $this->invalid();
        $this->keys($page['seo'], ['title', 'description']);
        $this->text($page['seo']['title'], 160, true);
        $this->text($page['seo']['description'], 320, true);
        $doc = $page['document'];
        $this->keys($doc, ['schemaVersion', 'blocks']);
        if ($doc['schemaVersion'] !== 1 || !is_array($doc['blocks']) || !array_is_list($doc['blocks']) || count($doc['blocks']) > 100) $this->invalid();
        $ids = [];
        foreach ($doc['blocks'] as $block) {
            if (!is_array($block)) $this->invalid();
            $this->keys($block, ['id', 'type', 'props'], ['hidden', 'variant', 'align']);
            if (array_key_exists('hidden', $block) && !is_bool($block['hidden'])) $this->invalid();
            if (array_key_exists('variant', $block) && !in_array($block['variant'], ['light', 'color'], true)) $this->invalid();
            if (array_key_exists('align', $block) && !in_array($block['align'], ['left', 'center'], true)) $this->invalid();
            if (!is_string($block['id']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $block['id']) || isset($ids[$block['id']]) || !is_array($block['props'])) $this->invalid();
            $ids[$block['id']] = true;
            $props = $block['props'];
            switch ($block['type']) {
                case 'catalog':
                    $this->keys($props, ['title', 'mode', 'category', 'codes', 'limit', 'buttonLabel']);
                    $this->text($props['title'], 200, true);
                    $this->text($props['buttonLabel'], 100, true);
                    if (!in_array($props['mode'], ['selection', 'category'], true) || !in_array($props['category'], ['prestations', 'produits'], true)) $this->invalid();
                    if (!is_int($props['limit']) || $props['limit'] < 1 || $props['limit'] > 12) $this->invalid();
                    if (!is_array($props['codes']) || !array_is_list($props['codes']) || count($props['codes']) > 12 || count(array_unique($props['codes'], SORT_REGULAR)) !== count($props['codes'])) $this->invalid();
                    foreach ($props['codes'] as $code) $this->text($code, 255);
                    break;
                case 'giftCard':
                    $this->keys($props, ['title', 'text', 'image', 'buttonLabel']);
                    $this->text($props['title'], 200, true);
                    $this->text($props['text'], 2000, true);
                    $this->text($props['buttonLabel'], 100);
                    if ($props['image'] !== null) $this->image($props['image']);
                    break;
                case 'image':
                    $this->image($props);
                    break;
                case 'banner':
                    // Preserve image-only banners already saved by the media editor.
                    if (!array_key_exists('title', $props)) { $this->image($props); break; }
                    $this->keys($props, ['mediaId', 'alt', 'title', 'text', 'button']);
                    if ($props['mediaId'] !== null) $this->image(['mediaId' => $props['mediaId'], 'alt' => $props['alt']]);
                    $this->text($props['title'], 200, true);
                    $this->text($props['text'], 2000, true);
                    $this->button($props['button']);
                    break;
                case 'imageText':
                    $this->keys($props, ['image', 'title', 'content', 'position']);
                    if ($props['image'] !== null) $this->image($props['image']);
                    $this->text($props['title'], 200, true);
                    $this->richText($props['content']);
                    if (!in_array($props['position'], ['left', 'right'], true)) $this->invalid();
                    break;
                case 'faq':
                    $this->keys($props, ['title', 'items']);
                    $this->text($props['title'], 200, true);
                    if (!is_array($props['items']) || !array_is_list($props['items']) || count($props['items']) > 30) $this->invalid();
                    foreach ($props['items'] as $item) {
                        if (!is_array($item)) $this->invalid();
                        $this->keys($item, ['question', 'answer']);
                        $this->text($item['question'], 300);
                        $this->richText($item['answer']);
                    }
                    break;
                case 'practical':
                    $this->keys($props, ['title', 'address', 'phone', 'email', 'hours', 'link']);
                    foreach (['title' => 200, 'address' => 1000, 'phone' => 40, 'email' => 254, 'hours' => 2000] as $field => $max) $this->text($props[$field], $max, true);
                    if ($props['email'] !== '' && !filter_var($props['email'], FILTER_VALIDATE_EMAIL)) $this->invalid();
                    if ($props['link'] !== null) $this->link($props['link']);
                    break;
                case 'gallery':
                    $this->keys($props, ['images']);
                    if (!is_array($props['images']) || !array_is_list($props['images']) || count($props['images']) > 20) $this->invalid();
                    foreach ($props['images'] as $image) $this->image($image);
                    break;
                case 'heading':
                    $this->keys($props, ['text', 'level']);
                    $this->text($props['text'], 200);
                    if (!in_array($props['level'], [2, 3], true)) $this->invalid();
                    break;
                case 'text':
                    $this->keys($props, ['content']);
                    $this->richText($props['content']);
                    break;
                case 'button':
                    $this->keys($props, ['label', 'link']);
                    $this->text($props['label'], 100);
                    $this->link($props['link']);
                    break;
                default: $this->invalid();
            }
        }
        if (strlen(json_encode($page, JSON_THROW_ON_ERROR)) > 200000) $this->invalid();
        return $page;
    }
    private function image(mixed $image): void
    {
        if (!is_array($image)) $this->invalid();
        $this->keys($image, ['mediaId', 'alt']);
        if (!is_string($image['mediaId']) || !preg_match('/^[a-f0-9]{32}$/D', $image['mediaId'])) $this->invalid();
        $this->text($image['alt'], 300, true);
    }
    private function button(mixed $button): void
    {
        if ($button === null) return;
        if (!is_array($button)) $this->invalid();
        $this->keys($button, ['label', 'link']);
        $this->text($button['label'], 100);
        $this->link($button['link']);
    }
    /** Closed Tiptap tree, bounded depth/size; never accept HTML or arbitrary attributes. */
    private function richText(mixed $doc): void
    {
        if (!is_array($doc) || ($doc['type'] ?? null) !== 'doc') $this->invalid();
        $count = 0;
        $this->richNode($doc, ['doc'], 0, $count);
    }
    private function richNode(mixed $node, array $allowed, int $depth, int &$count): void
    {
        if (!is_array($node) || $depth > 8 || ++$count > 3000 || !in_array($node['type'] ?? null, $allowed, true)) $this->invalid();
        $type = $node['type'];
        if ($type === 'text') {
            $this->keys($node, ['type', 'text'], ['marks']);
            $this->text($node['text'], 10000);
            $marks = $node['marks'] ?? [];
            if (!is_array($marks) || !array_is_list($marks) || count($marks) > 3) $this->invalid();
            foreach ($marks as $mark) {
                if (!is_array($mark)) $this->invalid();
                if (($mark['type'] ?? '') === 'link') {
                    $this->keys($mark, ['type', 'attrs']);
                    if (!is_array($mark['attrs'])) $this->invalid();
                    $this->keys($mark['attrs'], ['href']);
                    $this->link(['type' => 'external', 'target' => $mark['attrs']['href']]);
                } else {
                    $this->keys($mark, ['type']);
                    if (!in_array($mark['type'], ['bold', 'italic'], true)) $this->invalid();
                }
            }
            return;
        }
        $this->keys($node, ['type'], $type === 'heading' ? ['content', 'attrs'] : ['content']);
        if ($type === 'heading') {
            if (!is_array($node['attrs'] ?? null)) $this->invalid();
            $this->keys($node['attrs'], ['level']);
            if (!in_array($node['attrs']['level'], [2, 3], true)) $this->invalid();
        }
        $children = array_key_exists('content', $node) ? $node['content'] : [];
        if (!is_array($children) || !array_is_list($children) || count($children) > 200) $this->invalid();
        $allowedChildren = match ($type) {
            'doc' => ['paragraph', 'heading', 'bulletList', 'orderedList'],
            'bulletList', 'orderedList' => ['listItem'],
            'listItem' => ['paragraph'],
            default => ['text'],
        };
        foreach ($children as $child) $this->richNode($child, $allowedChildren, $depth + 1, $count);
    }
    public function link(mixed $link): array
    {
        if (!is_array($link)) $this->invalid();
        $this->keys($link, ['type', 'target']);
        if (!is_string($link['target'])) $this->invalid();
        $valid = match ($link['type']) {
            'page' => preg_match('/^[a-f0-9]{32}$/D', $link['target']) === 1,
            'route' => array_key_exists($link['target'], self::ROUTES),
            'external' => strlen($link['target']) <= 2048 && filter_var($link['target'], FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($link['target'], PHP_URL_SCHEME) ?? ''), ['https', 'http'], true) && !preg_match('/[\s<>\\\\]/', $link['target']) && parse_url($link['target'], PHP_URL_USER) === null && parse_url($link['target'], PHP_URL_PASS) === null,
            default => false,
        };
        if (!$valid) throw new \InvalidArgumentException('Lien invalide.');
        return $link;
    }
    public function menu(mixed $items, int $depth = 0): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > 30) $this->invalid();
        foreach ($items as $item) {
            if (!is_array($item)) $this->invalid();
            $this->keys($item, ['label', 'link'], $depth === 0 ? ['children', 'hidden'] : ['hidden']);
            if (isset($item['hidden']) && !is_bool($item['hidden'])) $this->invalid();
            if (array_key_exists('hidden', $item) && $item['hidden'] === null) $this->invalid();
            $this->text($item['label'], 100);
            $this->link($item['link']);
            if (array_key_exists('children', $item)) $this->menu($item['children'], 1);
        }
        return $items;
    }
    private function invalid(): never { throw new \InvalidArgumentException('Contenu invalide ou non pris en charge.'); }
}
