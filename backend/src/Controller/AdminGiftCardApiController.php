<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GiftCard\GiftCardView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Firewall api_admin + permission Finances ; connexion et filtre de l’établissement courant. */
final class AdminGiftCardApiController
{
    public function __construct(private readonly GiftCardView $view) {}

    #[Route('/api/v2/admin/gift-cards', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse($this->view->cards($this->page($request)));
    }

    #[Route('/api/v2/admin/gift-cards/{id}/movements', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function movements(int $id, Request $request): JsonResponse
    {
        $result = $this->view->movements($id, $this->page($request));
        return new JsonResponse($result ?? ['message' => 'Carte cadeau introuvable.'], $result === null ? 404 : 200);
    }

    private function page(Request $request): int
    {
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($page === false) throw new BadRequestHttpException('Le numéro de page est invalide.');
        return $page;
    }
}
