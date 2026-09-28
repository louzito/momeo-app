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
            $this->keys($block, ['id', 'type', 'props']);
            if (!is_string($block['id']) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $block['id']) || isset($ids[$block['id']]) || !is_array($block['props'])) $this->invalid();
            $ids[$block['id']] = true;
            $props = $block['props'];
            switch ($block['type']) {
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
    // Minimal Tiptap document: paragraphs, text, bold and italic. No HTML or arbitrary attributes.
    private function richText(mixed $doc): void
    {
        if (!is_array($doc)) $this->invalid();
        $this->keys($doc, ['type', 'content']);
        if ($doc['type'] !== 'doc' || !is_array($doc['content']) || !array_is_list($doc['content']) || count($doc['content']) > 100) $this->invalid();
        foreach ($doc['content'] as $paragraph) {
            if (!is_array($paragraph)) $this->invalid();
            $this->keys($paragraph, ['type'], ['content']);
            if ($paragraph['type'] !== 'paragraph') $this->invalid();
            $children = array_key_exists('content', $paragraph) ? $paragraph['content'] : [];
            if (!is_array($children) || !array_is_list($children) || count($children) > 200) $this->invalid();
            foreach ($children as $text) {
                if (!is_array($text)) $this->invalid();
                $this->keys($text, ['type', 'text'], ['marks']);
                if ($text['type'] !== 'text') $this->invalid();
                $this->text($text['text'], 10000);
                $marks = array_key_exists('marks', $text) ? $text['marks'] : [];
                if (!is_array($marks) || !array_is_list($marks) || count($marks) > 2) $this->invalid();
                foreach ($marks as $mark) {
                    if (!is_array($mark)) $this->invalid();
                    $this->keys($mark, ['type']);
                    if (!in_array($mark['type'], ['bold', 'italic'], true)) $this->invalid();
                }
            }
        }
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
