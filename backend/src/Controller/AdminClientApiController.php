<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Booking;
use App\Service\Customer\ClientDirectoryService;
use App\Service\Customer\ClientProfileService;
use App\Service\Customer\InvalidClientProfile;
use App\Service\Gdpr\CustomerDataManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v2/admin/clients')]
#[IsGranted('ROLE_API_ACCESS')]
final class AdminClientApiController
{
    public function __construct(
        private readonly ClientDirectoryService $directory,
        private readonly ClientProfileService $profiles,
        private readonly Security $security,
        private readonly CustomerDataManager $customerDataManager,
    ) {}

    #[Route('/{id}/export', name: 'todatempo_api_admin_client_export', methods: ['GET'])]
    public function export(string $id): JsonResponse
    {
        $booking = $this->directory->findBookingForClient($id);
        if (!$booking instanceof Booking) return new JsonResponse(['message' => 'Client introuvable.'], 404);
        $actor = $this->security->getUser()?->getUserIdentifier() ?? 'admin';

        return new JsonResponse($this->customerDataManager->export($booking->getCustomerEmail(), $actor), headers: [
            'Content-Disposition' => sprintf('attachment; filename="donnees-client-%s.json"', $id),
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    #[Route('/{id}', name: 'todatempo_api_admin_client_erase', methods: ['DELETE'])]
    public function erase(string $id): JsonResponse
    {
        $booking = $this->directory->findBookingForClient($id);
        if (!$booking instanceof Booking) return new JsonResponse(['message' => 'Client introuvable.'], 404);
        $actor = $this->security->getUser()?->getUserIdentifier() ?? 'admin';

        return new JsonResponse(['status' => 'anonymized', 'counts' => $this->customerDataManager->erase($booking->getCustomerEmail(), $actor)]);
    }

    #[Route('', name: 'momeo_api_admin_client_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse($this->directory->list((string) $request->query->get('q', '')));
    }

    #[Route('/{id}', name: 'momeo_api_admin_client_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $booking = $this->directory->findBookingForClient($id);
        if (!$booking instanceof Booking) {
            return new JsonResponse(['message' => 'Client introuvable.'], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['message' => 'Corps JSON invalide.'], 400);
        }

        try {
            return new JsonResponse($this->profiles->update($booking, $data, $this->security->getUser()?->getUserIdentifier() ?? 'admin'));
        } catch (InvalidClientProfile $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 422);
        }
    }
}
