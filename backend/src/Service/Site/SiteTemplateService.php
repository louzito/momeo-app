<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\SiteMedia;
use App\Entity\Taxonomy\Taxon;
use App\Entity\Channel\Channel;
use Doctrine\ORM\EntityManagerInterface;

/** Templates contain only editorial sections and facts from the current tenant. */
final class SiteTemplateService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function document(string $template): array
    {
        if (!in_array($template, ['blank', 'home', 'presentation', 'contact'], true)) throw new \InvalidArgumentException('Ce modèle de page est indisponible.');
        if ($template === 'blank') return ['schemaVersion' => 1, 'blocks' => []];
        $taxon = $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'todatempo_config'])
            ?? $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config']);
        $raw = json_decode($taxon?->getTranslation('en_US')->getDescription() ?: '{}', true);
        $cfg = is_array($raw) ? ($raw['draft'] ?? $raw) : [];
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $plain = static fn ($value, int $max): string => mb_substr(trim(preg_replace('/[\x00-\x1F<>]/u', '', strip_tags(is_string($value) ? $value : '')) ?? ''), 0, $max);
        $name = $plain($cfg['name'] ?? $channel?->getName(), 200);
        $subtitle = $plain($cfg['home']['subtitle'] ?? '', 2000);
        $images = array_map(static fn (SiteMedia $media): array => ['mediaId' => $media->getId(), 'alt' => $media->toArray()['alt']], $this->em->getRepository(SiteMedia::class)->findBy([], ['id' => 'ASC'], 6));
        $block = static fn (string $type, array $props): array => ['id' => bin2hex(random_bytes(16)), 'type' => $type, 'props' => $props];
        $rich = static fn (string $text): array => ['type' => 'doc', 'content' => [$text === '' ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
        $email = $plain($cfg['contactEmail'] ?? $channel?->getContactEmail(), 254);
        $practical = $block('practical', ['title' => 'Nous contacter', 'address' => $plain(implode(', ', array_filter([$cfg['address']['street'] ?? '', trim(($cfg['address']['postcode'] ?? '').' '.($cfg['address']['city'] ?? ''))])), 1000), 'phone' => $plain($cfg['contactPhone'] ?? $channel?->getContactPhoneNumber(), 40), 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', 'hours' => '', 'link' => null]);
        $blocks = match ($template) {
            'home' => [
                $block('banner', ['mediaId' => $images[0]['mediaId'] ?? null, 'alt' => $images[0]['alt'] ?? '', 'title' => $plain($cfg['home']['title'] ?? $name, 200), 'text' => $subtitle, 'button' => ['label' => 'Voir les prestations', 'link' => ['type' => 'route', 'target' => 'services']]]),
                $block('catalog', ['title' => 'Nos prestations', 'mode' => 'category', 'category' => 'prestations', 'codes' => [], 'limit' => 3, 'buttonLabel' => 'Voir les prestations']),
                $practical,
            ],
            'presentation' => [
                $block('imageText', ['image' => $images[0] ?? null, 'title' => $name, 'content' => $rich($subtitle), 'position' => 'left']),
                $block('gallery', ['images' => $images]), $practical,
            ],
            'contact' => [$practical],
        };
        return ['schemaVersion' => 1, 'blocks' => $blocks];
    }
}
