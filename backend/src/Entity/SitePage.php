<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_site_page')]
class SitePage
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;
    #[ORM\Column(length: 120, unique: true)]
    private string $slug;
    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $role;
    #[ORM\Column(type: 'json')]
    private array $draft;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $published = null;
    #[ORM\Column(name: 'published_slug', length: 120, unique: true, nullable: true)]
    private ?string $publishedSlug = null;
    #[ORM\Column(name: 'previous_slugs', type: 'json', nullable: true)]
    private ?array $previousSlugs = null;
    #[ORM\Column(type: 'boolean')]
    private bool $archived = false;
    #[ORM\Column(type: 'integer')]
    #[ORM\Version]
    private int $revision = 1;

    #[ORM\Column(name: 'legacy_published', type: 'json', nullable: true)]
    private ?array $legacyPublished = null;
    public function getLegacyPublished(): ?array { return $this->legacyPublished; }
    public function preserveLegacyPublished(array $document): void
    {
        if ($this->legacyPublished !== null) throw new \LogicException('La reprise est déjà conservée.');
        $this->legacyPublished = $document;
    }

    public function __construct(array $draft, ?string $role = null)
    {
        if (!in_array($role, [null, 'home', 'terms', 'mentions'], true)) throw new \InvalidArgumentException('Rôle de page invalide.');
        $this->id = bin2hex(random_bytes(16));
        $this->role = $role;
        $this->draft = $draft;
        $this->slug = $draft['slug'];
    }
    public function getPreviousSlugs(): array { return $this->previousSlugs ?? []; }
    public function getId(): string { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getRole(): ?string { return $this->role; }
    public function getDraft(): array { return $this->draft; }
    public function getPublished(): ?array { return $this->published; }
    public function getRevision(): int { return $this->revision; }
    public function isArchived(): bool { return $this->archived; }
    public function revise(array $draft): void
    {
        if ($this->archived) throw new \InvalidArgumentException('Cette page est archivée.');
        if ($this->role !== null && $draft['slug'] !== $this->slug) throw new \InvalidArgumentException('L’adresse de cette page est protégée.');
        $this->draft = $draft;
        $this->slug = $draft['slug'];
    }
    public function publish(): void
    {
        if ($this->archived) throw new \InvalidArgumentException('Cette page est archivée.');
        if ($this->publishedSlug !== null && $this->publishedSlug !== $this->slug) $this->previousSlugs = array_values(array_unique([...$this->getPreviousSlugs(), $this->publishedSlug]));
        $this->published = $this->draft;
        $this->publishedSlug = $this->slug;
    }
    public function restore(): void
    {
        if ($this->published === null) throw new \InvalidArgumentException('Aucune version publiée à restaurer.');
        $this->revise($this->published);
    }
    public function archive(): void
    {
        if ($this->role !== null) throw new \InvalidArgumentException('Cette page est protégée.');
        $this->archived = true;
    }
}
