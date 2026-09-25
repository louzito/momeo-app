<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WaitlistRequest;
use App\Service\Waitlist\WaitlistManagement;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/shop/waitlist')]
final class ShopWaitlistApiController
{
    public function __construct(private readonly WaitlistManagement $waitlist) {}

    #[Route('', name: 'todatempo_shop_waitlist_create', methods: ['POST'])]
    public function create(Request $httpRequest): JsonResponse
    {
        $data = json_decode($httpRequest->getContent(), true);
        $data = \is_array($data) ? $data : [];
        if (($data['consent'] ?? false) !== true) return new JsonResponse(['error' => 'Votre accord explicite est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        $serviceCode = mb_substr(trim((string) ($data['serviceCode'] ?? '')), 0, 255);
        $firstName = mb_substr(trim((string) ($data['firstName'] ?? '')), 0, 100);
        $lastName = mb_substr(trim((string) ($data['lastName'] ?? '')), 0, 100);
        $email = mb_strtolower(mb_substr(trim((string) ($data['email'] ?? '')), 0, 180));
        try { $start = new \DateTimeImmutable((string) ($data['periodStart'] ?? '')); $end = new \DateTimeImmutable((string) ($data['periodEnd'] ?? '')); }
        catch (\Throwable) { return new JsonResponse(['error' => 'La période est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY); }
        if ($serviceCode === '' || $firstName === '' || $lastName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $end <= $start) {
            return new JsonResponse(['error' => 'La prestation, une période valide et vos coordonnées sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $entry = $this->waitlist->subscribe($serviceCode, $firstName, $lastName, $email, $start, $end);
        if ($entry === null) return new JsonResponse(['error' => 'Cette prestation n’est pas disponible.'], Response::HTTP_NOT_FOUND);
        return new JsonResponse(['id' => $entry->getId(), 'status' => $entry->getStatus(), 'message' => 'Inscription enregistrée. Aucune réservation ne sera créée automatiquement.'], Response::HTTP_CREATED);
    }

    #[Route('/{token<[0-9a-f]{64}>}/unsubscribe', name: 'todatempo_shop_waitlist_unsubscribe', methods: ['POST'])]
    public function unsubscribe(string $token): JsonResponse
    {
        $entry = $this->waitlist->unsubscribeByToken($token);
        if (!$entry instanceof WaitlistRequest) return new JsonResponse(['error' => 'Inscription introuvable.'], Response::HTTP_NOT_FOUND);
        return new JsonResponse(['status' => $entry->getStatus()]);
    }
}
