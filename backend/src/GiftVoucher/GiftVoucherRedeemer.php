<?php

declare(strict_types=1);

namespace App\GiftVoucher;

use App\Entity\GiftVoucher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cheques cadeaux reels — redemption par le beneficiaire (Phase 2, espace
 * beneficiaire). Transition REELLE et persistee active -> used : c'est elle
 * qui empeche un cheque d'etre reserve deux fois (idempotence naturelle du
 * statut stocke, pas besoin de verrou supplementaire vu le volume).
 *
 * Ce qui n'est PAS gere ici (limitation assumee, deja en place pour l'achat
 * direct) : le creneau / la carte d'embarquement reserves restent portes par
 * la couche mock front (aucune commande Sylius creee) tant que le metier
 * "creneaux" n'est pas porte cote back — seul le passage a `used` du
 * GiftVoucher est reel.
 */
final class GiftVoucherRedeemer
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @throws \DomainException si le cheque n'est pas utilisable en l'etat */
    public function redeem(GiftVoucher $voucher): void
    {
        if (!$voucher->isUsable()) {
            throw new \DomainException(match ($voucher->getEffectiveStatus()) {
                GiftVoucher::STATUS_AWAITING_PAYMENT => "Ce cheque cadeau n'est pas encore actif : le paiement de l'achat est toujours en attente.",
                GiftVoucher::STATUS_USED => 'Ce cheque cadeau a deja ete utilise.',
                GiftVoucher::STATUS_EXPIRED => 'Ce cheque cadeau est expire.',
                default => "Ce cheque cadeau n'est pas utilisable pour le moment.",
            });
        }

        $voucher->setStatus(GiftVoucher::STATUS_USED);
        $voucher->setUsedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
