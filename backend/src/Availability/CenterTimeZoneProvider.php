<?php

declare(strict_types=1);

namespace App\Availability;

use App\Configuration\SiteConfigDocument;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;

final class CenterTimeZoneProvider
{
    private const DEFAULT_TIMEZONE = 'Europe/Paris';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function get(): \DateTimeZone
    {
        try {
            $taxon = $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => 'todatempo_config'])
                ?? $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config']);
            $config = SiteConfigDocument::published(json_decode($taxon?->getTranslation('en_US')?->getDescription() ?: '{}', true));
            $name = \is_array($config) && \is_string($config['timezone'] ?? null) ? $config['timezone'] : self::DEFAULT_TIMEZONE;

            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }
}
