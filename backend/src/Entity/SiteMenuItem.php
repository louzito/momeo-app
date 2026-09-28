<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'todatempo_site_menu_item')]
class SiteMenuItem
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;
    #[ORM\ManyToOne(targetEntity: SiteMenu::class)]
    #[ORM\JoinColumn(name: 'menu_location', referencedColumnName: 'location', nullable: false, onDelete: 'CASCADE')]
    private SiteMenu $menu;
    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $position;
    // Each root item stores at most one validated level of children.
    #[ORM\Column(type: 'json')]
    private array $document;

    public function __construct(SiteMenu $menu, int $position, array $document)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->menu = $menu;
        $this->position = $position;
        $this->document = $document;
    }
    public function getDocument(): array { return $this->document; }
}
