<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GiftVoucher;
use App\Repository\GiftVoucherRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SkyBook — mini API v2 admin pour les CHEQUES CADEAUX (Phase 3).
 *
 * Le GiftVoucher est une entite maison (pas une ressource API Platform) ->
 * meme raison d'etre que AdminInvoiceApiController : vivre sous
 * /api/v2/admin/... pour heriter du firewall `api_admin` (JWT +
 * ROLE_API_ACCESS), et coller au contrat attendu par l'espace centre du
 * front (adminApi.js / httpApi.js) :
 *
 *   GET /api/v2/admin/gift-vouchers?status=active  -> { member: [...], stats: {...} }
 *
 * Isolation tenant : AUTOMATIQUE, comme le reste du dossier GiftVoucher —
 * la table skybook_gift_voucher vit dans la BDD du centre courant
 * (TenantConnectionMiddleware bascule la connexion par requete), donc un
 * admin JWT d'un centre ne peut structurellement pas voir les cheques d'un
 * autre centre (pas de filtre explicite necessaire ici, comme pour les
 * commandes/factures deja exposees en admin).
 */
final class AdminGiftVoucherApiController
{
    public function __construct(
        private readonly GiftVoucherRepository $giftVoucherRepository,
    ) {
    }

    #[Route('/api/v2/admin/gift-vouchers', name: 'skybook_api_admin_gift_voucher_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->query->get('status', ''));

        $vouchers = $this->giftVoucherRepository->findBy([], ['createdAt' => 'DESC']);
        if ('' !== $status) {
            $vouchers = array_values(array_filter(
                $vouchers,
                static fn (GiftVoucher $v): bool => $v->getEffectiveStatus() === $status,
            ));
        }

        return new JsonResponse([
            'member' => array_map($this->normalize(...), $vouchers),
            'stats' => $this->giftVoucherRepository->countByEffectiveStatus(),
        ]);
    }

    /** @return array<string, mixed> */
    private function normalize(GiftVoucher $voucher): array
    {
        return [
            'code' => $voucher->getCode(),
            'status' => $voucher->getEffectiveStatus(),
            'serviceCode' => $voucher->getServiceCode(),
            'serviceName' => $voucher->getServiceName(),
            'jumpTypeCode' => $voucher->getServiceCode(),
            'jumpTypeName' => $voucher->getServiceName(),
            'amount' => $voucher->getAmount(),
            'currencyCode' => $voucher->getCurrencyCode(),
            'purchaserName' => $voucher->getPurchaserName(),
            'purchaserEmail' => $voucher->getPurchaserEmail(),
            'beneficiaryName' => $voucher->getBeneficiaryName(),
            'beneficiaryEmail' => $voucher->getBeneficiaryEmail(),
            'personalMessage' => $voucher->getPersonalMessage(),
            'purchaseOrderNumber' => $voucher->getPurchaseOrderNumber(),
            'usageOrderNumber' => $voucher->getUsageOrderNumber(),
            'expiresAt' => $voucher->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'createdAt' => $voucher->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'activatedAt' => $voucher->getActivatedAt()?->format(\DateTimeInterface::ATOM),
            'usedAt' => $voucher->getUsedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
