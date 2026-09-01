<?php

declare(strict_types=1);

namespace App\GiftVoucher;

use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lit `giftVoucherValidityMonths` dans le taxon skybook_config (meme JSON PAR
 * CENTRE que giftVouchersEnabled, Emails, etc. — voir Configuration boutique).
 * Meme pattern de lecture que App\Twig\SkybookEmailExtension (BDD du tenant
 * courant, pas de champ dedie a ajouter). Absent/invalide -> defaut 12 mois.
 */
final class GiftVoucherConfig
{
    private const DEFAULT_VALIDITY_MONTHS = 12;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function validityMonths(): int
    {
        $months = $this->config()['giftVoucherValidityMonths'] ?? null;
        $months = is_numeric($months) ? (int) $months : self::DEFAULT_VALIDITY_MONTHS;

        return $months > 0 ? $months : self::DEFAULT_VALIDITY_MONTHS;
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $taxon = $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config']);
            $data = json_decode($taxon?->getTranslation('en_US')?->getDescription() ?: '{}', true);

            return $this->cache = \is_array($data) ? $data : [];
        } catch (\Throwable) {
            return $this->cache = [];
        }
    }
}
