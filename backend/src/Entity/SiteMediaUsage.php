<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** A database foreign key also protects against concurrent deletion and page saves. */
#[ORM\Entity]
#[ORM\Table(name: 'todatempo_site_media_usage')]
class SiteMediaUsage
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: SitePage::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SitePage $page,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: SiteMedia::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private SiteMedia $media,
    ) {}
    public function getMedia(): SiteMedia { return $this->media; }
}
