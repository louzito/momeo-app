<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User\ShopUser;
use App\Service\Customer\CustomerGiftCards;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v2/shop/account/gift-cards')]
#[IsGranted('ROLE_USER')]
final class ShopCustomerGiftCardsController
{
    public function __construct(private readonly CustomerGiftCards $cards) {}

    #[Route('', methods: ['GET'])]
    public function index(#[CurrentUser] ShopUser $user): JsonResponse
    {
        return $this->response($this->cards->lists($user));
    }

    #[Route('/claim', methods: ['POST'])]
    public function claim(Request $request, #[CurrentUser] ShopUser $user): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_string($data['code'] ?? null)) {
            return $this->response(['error' => 'Saisissez le code de votre carte cadeau.'], 422);
        }
        try {
            $this->cards->claim($user, strtoupper(trim($data['code'])));
        } catch (\DomainException $e) {
            return $this->response(['error' => $e->getMessage()], 422);
        }
        return $this->response(['status' => 'attached']);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
