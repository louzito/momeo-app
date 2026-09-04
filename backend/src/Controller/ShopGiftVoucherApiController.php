<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\GiftVoucher;
use App\GiftVoucher\GiftVoucherQrCodeGenerator;
use App\Repository\BookingRepository;
use App\Repository\GiftVoucherRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API shop des cheques cadeaux (public, comme le reste de /api/v2/shop —
 * isolation par tenant automatique via la connexion Doctrine du centre
 * courant, aucun filtrage explicite necessaire).
 * Phase 1 : QR + lookup par commande (page de confirmation d'achat).
 * Phase 2 : espace beneficiaire reel — login (code + email), liste des
 * cheques d'un email, lookup par code. La redemption (active -> used) est
 * reelle et atomique, portee par ShopBookingApiController::createFromVoucher
 * (transaction + verrou pessimiste + creation du Booking reel) : c'est la
 * SEULE voie de consommation d'un cheque, il n'y en a pas d'autre ici.
 *
 * Securite : meme modele que le reste du checkout API Sylius de ce projet
 * (ShopGiftOrderMarkerController) — connaitre le code (10 chiffres, genere
 * serveur, non sequentiel) ET l'email beneficiaire = preuve de possession du
 * cheque, pas de compte/JWT dedie pour cet espace.
 */
final class ShopGiftVoucherApiController
{
    public function __construct(
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly GiftVoucherQrCodeGenerator $qrCodeGenerator,
        private readonly BookingRepository $bookingRepository,
    ) {
    }

    #[Route(
        '/api/v2/shop/gift-vouchers/{code}/qr.png',
        name: 'skybook_api_shop_gift_voucher_qr',
        methods: ['GET'],
        requirements: ['code' => '\d{10}'],
    )]
    public function qr(string $code): Response
    {
        $voucher = $this->giftVoucherRepository->findOneByCode($code);
        if (!$voucher instanceof GiftVoucher) {
            return new JsonResponse(['error' => 'Chèque introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new Response($this->qrCodeGenerator->generatePng($code), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    #[Route(
        '/api/v2/shop/gift-vouchers/by-order/{orderNumber}',
        name: 'skybook_api_shop_gift_voucher_by_order',
        methods: ['GET'],
    )]
    public function byOrderNumber(string $orderNumber): JsonResponse
    {
        $voucher = $this->giftVoucherRepository->findOneByPurchaseOrderNumber($orderNumber);
        if (!$voucher instanceof GiftVoucher) {
            return new JsonResponse(['error' => 'Chèque introuvable pour cette commande.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalize($voucher));
    }

    /**
     * Connexion beneficiaire : code + email -> profil minimal. Ne verifie PAS
     * le statut (un beneficiaire doit pouvoir se connecter pour VOIR un
     * cheque expire/deja utilise, seule la reservation l'exige).
     */
    #[Route('/api/v2/shop/gift-vouchers/login', name: 'skybook_api_shop_gift_voucher_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            $payload = [];
        }
        $code = trim((string) ($payload['code'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        if ($code === '' || $email === '') {
            return new JsonResponse(['error' => 'Code et email requis.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $voucher = $this->giftVoucherRepository->findOneByCode($code);
        if (!$voucher instanceof GiftVoucher || 0 !== strcasecmp($voucher->getBeneficiaryEmail(), $email)) {
            return new JsonResponse(['error' => "Ce code ne correspond pas a cet email."], Response::HTTP_UNAUTHORIZED);
        }

        $firstName = trim((string) strtok((string) $voucher->getBeneficiaryName(), ' '));

        return new JsonResponse([
            'email' => $voucher->getBeneficiaryEmail(),
            'firstName' => $firstName !== '' ? $firstName : null,
        ]);
    }

    /** Tous les cheques recus par cet email (tableau de bord beneficiaire). */
    #[Route('/api/v2/shop/gift-vouchers/by-email/{email}', name: 'skybook_api_shop_gift_voucher_by_email', methods: ['GET'])]
    public function byEmail(string $email): JsonResponse
    {
        $vouchers = $this->giftVoucherRepository->findByEmail(trim($email));

        return new JsonResponse(array_map($this->normalize(...), $vouchers));
    }

    /** Lookup par code (etapes reservation / expiration / confirmation). */
    #[Route(
        '/api/v2/shop/gift-vouchers/{code}',
        name: 'skybook_api_shop_gift_voucher_show',
        methods: ['GET'],
        requirements: ['code' => '\d{10}'],
    )]
    public function show(string $code): JsonResponse
    {
        $voucher = $this->giftVoucherRepository->findOneByCode($code);
        if (!$voucher instanceof GiftVoucher) {
            return new JsonResponse(['error' => 'Chèque cadeau introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalize($voucher));
    }

    /** @return array<string, mixed> */
    private function normalize(GiftVoucher $voucher): array
    {
        $booking = null;
        if ($voucher->getUsageOrderNumber() !== null) {
            $usedBooking = $this->bookingRepository->findOneBy(['reference' => $voucher->getUsageOrderNumber()]);
            if ($usedBooking instanceof Booking) {
                $booking = [
                    'reference' => $usedBooking->getReference(),
                    'jumpTypeName' => $usedBooking->getServiceName(),
                    'slotStart' => $usedBooking->getSlotStart()->format(\DateTimeInterface::ATOM),
                    'slotEnd' => $usedBooking->getSlotEnd()->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        return [
            'code' => $voucher->getCode(),
            'status' => $voucher->getEffectiveStatus(),
            'serviceCode' => $voucher->getServiceCode(),
            'serviceName' => $voucher->getServiceName(),
            'jumpTypeCode' => $voucher->getServiceCode(),
            'jumpTypeName' => $voucher->getServiceName(),
            'amount' => $voucher->getAmount(),
            'currencyCode' => $voucher->getCurrencyCode(),
            'beneficiaryName' => $voucher->getBeneficiaryName(),
            'beneficiaryEmail' => $voucher->getBeneficiaryEmail(),
            'personalMessage' => $voucher->getPersonalMessage(),
            'purchaserName' => $voucher->getPurchaserName(),
            'expiresAt' => $voucher->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'booking' => $booking,
        ];
    }
}
