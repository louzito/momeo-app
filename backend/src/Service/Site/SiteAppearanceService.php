<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/** Patches the historical configuration document; never creates a second theme. */
final class SiteAppearanceService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SiteManagementService $pages, private readonly SiteDocumentValidator $validator) {}

    private function taxon(): Taxon
    {
        return $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'todatempo_config'])
            ?? $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config'])
            ?? throw new \InvalidArgumentException('Enregistrez d’abord les informations de votre établissement.');
    }
    public function state(): array
    {
        $raw = $this->taxon()->getTranslation('en_US')->getDescription() ?: '{}';
        $primary = $this->pages->menu('main')?->getPrimaryLink();
        return ['revision' => hash('sha256', $raw.json_encode($primary)), 'primaryLink' => $primary];
    }
    public function save(array $data): array
    {
        $this->validator->keys($data, ['revision', 'colors', 'branding', 'typography', 'logo', 'primaryLink']);
        self::validateTheme($data);
        if (!is_string($data['revision']) || ($data['logo'] !== null && !is_string($data['logo']))) throw new \InvalidArgumentException('Apparence invalide.');
        if ($data['primaryLink'] !== null) {
            if (!is_array($data['primaryLink'])) throw new \InvalidArgumentException('Bouton invalide.');
            $this->validator->link($data['primaryLink']);
        }
        return $this->em->wrapInTransaction(function () use ($data): array {
            $taxon = $this->taxon();
            $this->em->lock($taxon, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($taxon);
            if (!hash_equals($this->state()['revision'], $data['revision'])) throw new \DomainException('L’apparence a changé dans une autre fenêtre. Rechargez avant de réessayer.');
            if ($data['logo'] !== null) {
                $owned = false;
                foreach (['todatempo_config', 'skybook_config'] as $code) {
                    $owner = $this->em->getRepository(Taxon::class)->findOneBy(['code' => $code]);
                    foreach ($owner?->getImages() ?? [] as $image) if (in_array($image->getType(), ['logo', 'draft_logo'], true) && $image->getPath() === $data['logo']) $owned = true;
                }
                if (!$owned) throw new \InvalidArgumentException('Ce logo est introuvable dans votre établissement.');
            }
            $translation = $taxon->getTranslation('en_US');
            $raw = json_decode($translation->getDescription() ?: '{}', true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($raw)) throw new \InvalidArgumentException('Configuration invalide.');
            $document = ($raw['schemaVersion'] ?? null) === 1 && isset($raw['draft'], $raw['published']) ? $raw : ['schemaVersion' => 1, 'revision' => 0, 'draft' => $raw, 'published' => $raw, 'publishedAt' => null];
            foreach (['colors', 'branding', 'typography'] as $key) $document['draft'][$key] = $data[$key];
            // null keeps the historical logo fallback; uploaded assets use tenant-owned paths only.
            if ($data['logo'] !== null) $document['draft']['assets']['logo'] = $data['logo'];
            $menu = $this->pages->menu('main');
            $this->pages->saveMenu('main', ['items' => $menu ? $this->pages->menuItems($menu) : [], 'primaryLink' => $data['primaryLink']]);
            $translation->setDescription(json_encode($document, JSON_THROW_ON_ERROR));
            $this->em->flush();
            return $this->state();
        });
    }
    public static function validateTheme(array $data): void
    {
        $validator = new SiteDocumentValidator();
        if (!is_array($data['colors'] ?? null) || !is_array($data['branding'] ?? null)) throw new \InvalidArgumentException('Couleurs invalides.');
        $validator->keys($data['colors'], ['header', 'textHeader', 'footer', 'textFooter']);
        foreach ($data['colors'] as $value) if (!is_string($value) || !preg_match('/^#[a-f0-9]{6}$/iD', $value)) throw new \InvalidArgumentException('Choisissez une couleur valide.');
        foreach (['header' => 'textHeader', 'footer' => 'textFooter'] as $background => $text) {
            $a = self::luminance($data['colors'][$background]); $b = self::luminance($data['colors'][$text]);
            if ((max($a, $b) + .05) / (min($a, $b) + .05) < 4.5) throw new \InvalidArgumentException('Le texte doit être suffisamment contrasté avec son fond (4,5:1 minimum).');
        }
        $validator->keys($data['branding'], ['brandPalette', 'accent']);
        if (!in_array($data['branding']['brandPalette'], ['sky', 'emerald', 'violet'], true) || !in_array($data['branding']['accent'], ['orange', 'amber', 'rose', 'cyan'], true) || !in_array($data['typography'] ?? null, ['modern', 'classic', 'system'], true)) throw new \InvalidArgumentException('Choisissez un style proposé.');
    }
    private static function luminance(string $hex): float
    {
        $values = array_map(static function (string $part): float { $v = hexdec($part) / 255; return $v <= .03928 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4; }, str_split(substr($hex, 1), 2));
        return .2126 * $values[0] + .7152 * $values[1] + .0722 * $values[2];
    }
}
