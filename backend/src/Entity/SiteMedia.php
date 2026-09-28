<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_site_media')]
class SiteMedia
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;
    public function __construct(
        #[ORM\Column(length: 255)] private string $path,
        #[ORM\Column(length: 255)] private string $thumbnail,
        #[ORM\Column(type: 'integer')] private int $width,
        #[ORM\Column(type: 'integer')] private int $height,
        #[ORM\Column(length: 300)] private string $alt,
    ) { $this->id = bin2hex(random_bytes(16)); }
    public function getId(): string { return $this->id; }
    public function getPath(): string { return $this->path; }
    public function getThumbnail(): string { return $this->thumbnail; }
    public function setAlt(string $alt): void { $this->alt = $alt; }
    public function toArray(): array { return ['id' => $this->id, 'path' => '/media/image/'.$this->path, 'thumbnail' => '/media/image/'.$this->thumbnail, 'width' => $this->width, 'height' => $this->height, 'alt' => $this->alt]; }
}
