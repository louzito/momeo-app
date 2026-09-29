<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_site_menu')]
class SiteMenu
{
    #[ORM\Id]
    #[ORM\Column(length: 20)]
    private string $location;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $published = null;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $primaryLink = null;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $publishedPrimaryLink = null;
    #[ORM\Column(type: 'integer')]
    #[ORM\Version]
    private int $revision = 1;

    #[ORM\Column(type: 'integer')]
    private int $draftVersion = 0;
    public function touchDraft(): void { ++$this->draftVersion; }

    public function __construct(string $location)
    {
        if (!in_array($location, ['main', 'footer'], true)) throw new \InvalidArgumentException('Emplacement de menu invalide.');
        $this->location = $location;
    }
    public function getLocation(): string { return $this->location; }
    public function getPublished(): ?array { return $this->published; }
    public function getRevision(): int { return $this->revision; }
    public function getPrimaryLink(): ?array { return $this->primaryLink; }
    public function getPublishedPrimaryLink(): ?array { return $this->publishedPrimaryLink; }
    public function setPrimaryLink(?array $link): void { $this->primaryLink = $link; }
    public function publish(array $items): void { $this->published = $items; $this->publishedPrimaryLink = $this->primaryLink; }
}
