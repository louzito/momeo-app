<?php

declare(strict_types=1);

namespace App\GiftVoucher;

use App\Tenant\TenantContext;
use App\Tenant\TenantUrlGenerator;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

/**
 * QR code d'activation d'un cheque cadeau : encode l'URL
 * "{base}/{slug}/beneficiary/login?code={code}" (scanner = arrive connecte
 * sur son espace beneficiaire ; le code seul reste utilisable a la main).
 * Generation SERVEUR (endroid/qr-code, sans bundle — usage direct de
 * Builder::build()), pas de dependance a un service externe.
 *
 * skybook.public_base_url reutilise le DEFAULT_URI existant (deja utilise par
 * Symfony pour generer des URLs en contexte CLI) — http://localhost en dev,
 * a surcharger en prod via DEFAULT_URI. NB dev : un QR pointant vers
 * localhost n'est PAS scannable depuis un vrai telephone hors de la machine.
 */
final class GiftVoucherQrCodeGenerator
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantUrlGenerator $urlGenerator,
    ) {
    }

    public function activationUrl(string $code): string
    {
        return $this->urlGenerator->url(
            $this->tenantContext->getSlug(),
            'beneficiary/login?code='.rawurlencode($code),
        );
    }

    /** @return string PNG brut */
    public function generatePng(string $code): string
    {
        $result = (new Builder())->build(
            writer: new PngWriter(),
            data: $this->activationUrl($code),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 320,
            margin: 12,
        );

        return $result->getString();
    }

    public function generateDataUri(string $code): string
    {
        return 'data:image/png;base64,' . base64_encode($this->generatePng($code));
    }
}
