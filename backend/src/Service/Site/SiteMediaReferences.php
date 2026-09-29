<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\{SiteMedia, SiteMediaUsage, SitePage};
use Doctrine\ORM\EntityManagerInterface;

final class SiteMediaReferences
{
    public function __construct(private readonly EntityManagerInterface $em) {}
    public static function ids(?array $document): array
    {
        $ids = [];
        foreach ($document['document']['blocks'] ?? [] as $block) {
            $images = match ($block['type']) {
                'image', 'banner' => empty($block['props']['mediaId']) ? [] : [$block['props']],
                'imageText', 'giftCard' => empty($block['props']['image']) ? [] : [$block['props']['image']],
                'gallery' => $block['props']['images'],
                default => [],
            };
            foreach ($images as $image) $ids[] = $image['mediaId'];
        }
        return array_values(array_unique($ids));
    }
    public function validate(array $document): void
    {
        foreach (self::ids($document) as $id) $this->find($id);
    }
    private function find(string $id): SiteMedia
    {
        return $this->em->find(SiteMedia::class, $id) ?? throw new \InvalidArgumentException('Une image est introuvable dans cet établissement. Choisissez une autre image.');
    }
    /** Called in the same flush as the page, retaining both draft and published references. */
    public function sync(SitePage $page): void
    {
        $ids = array_unique([...self::ids($page->getDraft()), ...self::ids($page->getPublished())]);
        foreach ($this->em->getRepository(SiteMediaUsage::class)->findBy(['page' => $page]) as $usage) {
            $id = $usage->getMedia()->getId();
            if (!in_array($id, $ids, true)) $this->em->remove($usage);
            else $ids = array_diff($ids, [$id]);
        }
        foreach ($ids as $id) $this->em->persist(new SiteMediaUsage($page, $this->find($id)));
    }
    public function publicMedia(array $document): array
    {
        $result = [];
        foreach (self::ids($document) as $id) {
            $result[$id] = $this->find($id)->toArray();
            unset($result[$id]['alt']); // The page snapshot owns its alternative text.
        }
        return $result;
    }
}
