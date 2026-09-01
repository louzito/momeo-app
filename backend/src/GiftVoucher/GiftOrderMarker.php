<?php

declare(strict_types=1);

namespace App\GiftVoucher;

/**
 * Marqueur machine-lisible pose dans Order::$notes (champ natif Sylius,
 * confirme via getNotes/setNotes) pour transporter les infos BENEFICIAIRE au
 * travers du checkout API standard, sans toucher aux DTO vendor (UpdateCart /
 * CompleteOrder n'ont pas de champ "notes" exploitable pour ca). Le front
 * pose ce marqueur via PATCH /api/v2/shop/orders/{tokenValue}/gift-marker
 * (App\Controller\ShopGiftOrderMarkerController) APRES l'etape adresse et
 * AVANT le PATCH complete ; App\EventListener\CreateGiftVoucherOnOrderPlacedListener
 * le lit au passage en checkoutState=completed pour creer le GiftVoucher.
 *
 * Format stocke : "SKYBOOK_GIFT" suivi d'un JSON compact — prefixe choisi
 * pour rester repérable a l'oeil dans /admin (grid des commandes affiche les
 * notes) sans etre confondu avec des notes libres saisies autrement.
 */
final class GiftOrderMarker
{
    public const PREFIX = 'SKYBOOK_GIFT';

    private function __construct(
        public readonly ?string $beneficiaryName,
        public readonly string $beneficiaryEmail,
        public readonly ?string $personalMessage,
    ) {
    }

    public static function create(?string $beneficiaryName, string $beneficiaryEmail, ?string $personalMessage): self
    {
        return new self(
            $beneficiaryName !== null && trim($beneficiaryName) !== '' ? trim($beneficiaryName) : null,
            trim($beneficiaryEmail),
            $personalMessage !== null && trim($personalMessage) !== '' ? trim($personalMessage) : null,
        );
    }

    public function encode(): string
    {
        return self::PREFIX . json_encode([
            'beneficiaryName' => $this->beneficiaryName,
            'beneficiaryEmail' => $this->beneficiaryEmail,
            'personalMessage' => $this->personalMessage,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    public static function decode(?string $notes): ?self
    {
        if ($notes === null || !str_starts_with($notes, self::PREFIX)) {
            return null;
        }
        $data = json_decode(substr($notes, \strlen(self::PREFIX)), true);
        if (!\is_array($data) || !\is_string($data['beneficiaryEmail'] ?? null) || trim((string) $data['beneficiaryEmail']) === '') {
            return null;
        }

        return new self(
            \is_string($data['beneficiaryName'] ?? null) ? $data['beneficiaryName'] : null,
            $data['beneficiaryEmail'],
            \is_string($data['personalMessage'] ?? null) ? $data['personalMessage'] : null,
        );
    }
}
