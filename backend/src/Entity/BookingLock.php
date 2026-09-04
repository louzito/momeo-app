<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Internal InnoDB mutex row; booking code accesses it through DBAL only. */
#[ORM\Entity]
#[ORM\Table(name: 'momeo_booking_lock')]
class BookingLock
{
    #[ORM\Id]
    #[ORM\Column(name: 'lock_key', length: 255)]
    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
