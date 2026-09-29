<?php

declare(strict_types=1);
namespace App\Service\Site;

use App\Entity\SitePage;
use App\Entity\Taxonomy\Taxon;
use App\Entity\Channel\Channel;
use App\Entity\Product\Product;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Explicit, repeatable preparation. The historical document is never rewritten. */
final class SiteLegacyImportService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SiteManagementService $pages, private readonly SiteDocumentValidator $validator, private readonly SiteMediaReferences $references, private readonly SiteMediaService $media) {}

    public function import(): array
    {
        return $this->em->wrapInTransaction(function (): array {
            $taxon = $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'todatempo_config'])
                ?? $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config'])
                ?? throw new \InvalidArgumentException('Enregistrez d’abord les informations de votre établissement.');
            $this->em->lock($taxon, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($taxon);
            $raw = json_decode($taxon->getTranslation('en_US')->getDescription() ?: '{}', true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($raw)) throw new \InvalidArgumentException('Le site existant ne peut pas être repris.');
            $versioned = ($raw['schemaVersion'] ?? null) === 1 && isset($raw['draft'], $raw['published']);
            $draft = $versioned ? $raw['draft'] : $raw;
            $published = $versioned ? $raw['published'] : $raw;
            if (!is_array($draft) || !is_array($published)) throw new \InvalidArgumentException('Le site existant ne peut pas être repris.');
            $owned = []; $byType = []; $images = [];
            foreach (['todatempo_config', 'skybook_config'] as $code) {
                $owner = $this->em->getRepository(Taxon::class)->findOneBy(['code' => $code]);
                foreach ($owner?->getImages() ?? [] as $image) {
                    $owned[$image->getPath()] = true;
                    $byType[$image->getType()] ??= $image->getPath();
                }
            }
            $imageFor = function (array $cfg, bool $isDraft) use ($owned, $byType, &$images): ?array {
                $path = $cfg['assets']['banner'] ?? ($isDraft ? ($byType['draft_banner'] ?? null) : null) ?? $byType['banner'] ?? $cfg['branding']['heroImage'] ?? null;
                if (!$path) return null;
                if (!isset($owned[$path])) throw new \InvalidArgumentException('La bannière existante doit être ajoutée aux images de votre établissement avant la reprise.');
                $images[$path] ??= $this->media->importExisting($path, '');
                return ['mediaId' => $images[$path]->getId(), 'alt' => ''];
            };
            $created = [];
            foreach (['home' => ['Accueil', 'bienvenue'], 'terms' => ['Conditions générales', 'conditions-generales'], 'mentions' => ['Mentions légales', 'mentions-legales']] as $role => [$title, $base]) {
                if ($this->pages->rolePage($role)) continue;
                $slug = $base; $suffix = 1;
                $used = [];
                foreach ($this->pages->pages() as $page) $used = [...$used, $page->getSlug(), $page->getPublished()['slug'] ?? '', ...$page->getPreviousSlugs()];
                while (in_array($slug, $used, true)) $slug = $base.'-'.++$suffix;
                $draftPage = $this->document($role, $title, $slug, $draft, $role === 'home' ? $imageFor($draft, true) : null);
                $publishedPage = $this->document($role, $title, $slug, $published, $role === 'home' ? $imageFor($published, false) : null);
                $this->validator->page($draftPage); $this->validator->page($publishedPage);
                $page = new SitePage($draftPage, $role);
                $page->preserveLegacyPublished($publishedPage);
                $this->em->persist($page);
                $this->references->validate($draftPage); $this->references->validate($publishedPage);
                $this->references->sync($page);
                $this->em->flush();
                $created[] = $page->getId();
            }
            return ['created' => $created];
        });
    }
    public function document(string $role, string $title, string $slug, array $cfg, ?array $image = null): array
    {
        $block = static fn (string $type, array $props): array => ['id' => bin2hex(random_bytes(16)), 'type' => $type, 'props' => $props];
        // Historical input was plain text. Keep paragraphs, never interpret markup.
        $plain = static fn ($text): string => str_replace(['<', '>'], ['‹', '›'], preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $text) ?? '');
        $rich = static function (string $text) use ($plain): array {
            $nodes = [];
            foreach (explode("\n", $plain($text)) as $line) {
                $nodes[] = $line === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => array_map(static fn ($part): array => ['type' => 'text', 'text' => $part], mb_str_split($line, 10000))];
            }
            return ['type' => 'doc', 'content' => $nodes];
        };
        if ($role !== 'home') {
            $legal = $cfg['legal'][$role] ?? [];
            $blocks = [$block('heading', ['text' => $title, 'level' => 2])];
            if (trim($legal['content'] ?? '') !== '') $blocks[] = $block('text', ['content' => $rich($legal['content'])]) + ['hidden' => !($legal['enabled'] ?? false)];
        } else {
            $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
            $home = $cfg['home'] ?? [];
            $blocks = [$block('banner', ['mediaId' => $image['mediaId'] ?? null, 'alt' => $image['alt'] ?? '', 'title' => $plain(($home['title'] ?? '') ?: ($cfg['tagline'] ?? $cfg['name'] ?? $channel?->getName() ?? '')), 'text' => $plain(($home['subtitle'] ?? '') ?: ($cfg['about'] ?? '')), 'button' => ['label' => 'Découvrir nos prestations', 'link' => ['type' => 'route', 'target' => 'shop']]])];
            $highlights = $home['highlights'] ?? $cfg['highlights'] ?? [];
            if (!$highlights) $highlights = ['Réservation en ligne', 'Professionnels à votre écoute', 'Paiement sécurisé'];
            $codes = [];
            foreach ($home['featured'] ?? [] as $code) {
                $product = $this->em->getRepository(Product::class)->findOneBy(['code' => $code]);
                if ($product && $product->getTodatempoType() === 'service') $codes[] = $code;
            }
            $sections = array_values(array_unique([...($home['sections'] ?? []), 'highlights', 'catalog', 'gift']));
            foreach ($sections as $section) {
                if ($section === 'highlights') $blocks[] = $block('text', ['content' => $rich(implode("\n", $highlights))]);
                if ($section === 'catalog') {
                    $blocks[] = $block('heading', ['text' => $plain(($home['catalogTitle'] ?? '') ?: 'Nos prestations'), 'level' => 2]);
                    $blocks[] = $block('text', ['content' => $rich(($home['catalogText'] ?? '') ?: 'Découvrez nos services et réservez votre rendez-vous en quelques clics.')]);
                    $blocks[] = $block('catalog', ['title' => '', 'mode' => $codes ? 'selection' : 'category', 'category' => 'prestations', 'codes' => array_slice(array_values(array_unique($codes)), 0, 9), 'limit' => 9, 'buttonLabel' => 'Voir toutes les prestations']);
                }
                if ($section === 'gift') {
                    if (($cfg['giftVouchersEnabled'] ?? true) !== false) $blocks[] = $block('giftCard', ['title' => 'Offrez le choix avec une carte cadeau', 'text' => 'Un crédit à utiliser dans notre boutique, sans prestation ni date à choisir aujourd’hui.', 'image' => null, 'buttonLabel' => 'Offrir une carte cadeau']);
                    else $blocks[] = $block('heading', ['text' => 'Vous avez déjà un chèque cadeau ?', 'level' => 2]);
                    $blocks[] = $block('button', ['label' => 'Utiliser mon chèque cadeau', 'link' => ['type' => 'route', 'target' => 'beneficiary']]);
                }
            }
        }
        return ['title' => $title, 'slug' => $slug, 'document' => ['schemaVersion' => 1, 'blocks' => $blocks], 'seo' => ['title' => '', 'description' => '']];
    }
}
