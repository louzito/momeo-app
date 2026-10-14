<?php

declare(strict_types=1);

namespace App\Service\Email;

/** Render only the editor's supported structure; all text and variables are escaped. */
final class EmailRichText
{
    public static function render(array $node, array $vars = [], int $depth = 0): string
    {
        if (!is_array($node['content'] ?? []) || !is_array($node['marks'] ?? [])) throw new \InvalidArgumentException('Texte invalide.');
        if ($depth > 20) throw new \InvalidArgumentException('Texte trop imbriqué.');
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (($node['type'] ?? '') === 'text') {
            $html = $escape(strtr((string) ($node['text'] ?? ''), $vars));
            foreach ($node['marks'] ?? [] as $mark) {
                if (!is_array($mark)) throw new \InvalidArgumentException('Format de texte invalide.');
                $tag = ['bold' => 'strong', 'italic' => 'em'][$mark['type'] ?? ''] ?? null;
                if ($tag) $html = '<'.$tag.'>'.$html.'</'.$tag.'>';
                if (($mark['type'] ?? '') === 'link') {
                    $href = $mark['attrs']['href'] ?? '';
                    if (is_string($href) && preg_match('#^https?://[^\s<>\\\\]+$#i', $href)) $html = '<a href="'.$escape($href).'">'.$html.'</a>';
                }
            }
            return $html;
        }
        $html = '';
        foreach ($node['content'] ?? [] as $child) {
            if (!is_array($child)) throw new \InvalidArgumentException('Texte invalide.');
            $html .= self::render($child, $vars, $depth + 1);
        }
        $tag = match ($node['type'] ?? '') {
            'doc' => null, 'paragraph' => 'p', 'bulletList' => 'ul', 'orderedList' => 'ol', 'listItem' => 'li',
            'heading' => ($node['attrs']['level'] ?? 2) === 3 ? 'h3' : 'h2',
            default => throw new \InvalidArgumentException('Format de texte invalide.'),
        };
        return $tag ? '<'.$tag.'>'.$html.'</'.$tag.'>' : $html;
    }
}
