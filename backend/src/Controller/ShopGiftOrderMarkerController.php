<?php

declare(strict_types=1);

namespace App\Controller;

use App\GiftVoucher\GiftOrderMarker;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pose le marqueur "cheque cadeau" (App\GiftVoucher\GiftOrderMarker) sur le
 * panier en cours de checkout. Pourquoi un endpoint dedie plutot que le PUT
 * standard /shop/orders/{tokenValue} : ce PUT deserialize son body dans le
 * DTO vendor Sylius\Bundle\ApiBundle\Command\Checkout\UpdateCart (email,
 * billingAddress, shippingAddress, couponCode UNIQUEMENT — pas de champ
 * notes exploitable, verifie dans le code du bundle). On ecrit donc
 * directement Order::$notes (champ natif Sylius, getNotes/setNotes) via ce
 * petit controleur custom, appele par le front APRES l'etape adresse et
 * AVANT le PATCH .../complete du tunnel "En cadeau".
 *
 * Securite : meme modele que tout le reste du checkout API Sylius —
 * connaitre le tokenValue du panier = capacite d'agir dessus (public, pas
 * d'auth dediee). `findCartByTokenValue` ne renvoie que des paniers encore en
 * cours (pas de commande deja passee), donc pas de risque de modifier les
 * notes d'une commande déjà finalisée par ce biais.
 */
final class ShopGiftOrderMarkerController
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/v2/shop/orders/{tokenValue}/gift-marker', name: 'skybook_api_shop_order_gift_marker', methods: ['PATCH'])]
    public function __invoke(string $tokenValue, Request $request): JsonResponse
    {
        $order = $this->orderRepository->findCartByTokenValue($tokenValue);
        if ($order === null) {
            return new JsonResponse(['error' => 'Panier introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            $payload = [];
        }
        $beneficiaryEmail = trim((string) ($payload['beneficiaryEmail'] ?? ''));
        if ($beneficiaryEmail === '' || filter_var($beneficiaryEmail, \FILTER_VALIDATE_EMAIL) === false) {
            return new JsonResponse(['error' => "L'email du bénéficiaire est invalide."], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $marker = GiftOrderMarker::create(
            \is_string($payload['beneficiaryName'] ?? null) ? $payload['beneficiaryName'] : null,
            $beneficiaryEmail,
            \is_string($payload['personalMessage'] ?? null) ? $payload['personalMessage'] : null,
        );
        $order->setNotes($marker->encode());
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }
}
