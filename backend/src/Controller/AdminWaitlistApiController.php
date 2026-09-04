<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WaitlistRequest;
use App\Repository\WaitlistRequestRepository;
use App\Waitlist\WaitlistNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v2/admin/waitlist')]
final class AdminWaitlistApiController
{
    public function __construct(private readonly WaitlistRequestRepository $repository, private readonly EntityManagerInterface $entityManager, private readonly WaitlistNotifier $notifier) {}
    #[Route('', name: 'todatempo_admin_waitlist_index', methods: ['GET'])]
    public function index(): JsonResponse { return new JsonResponse(['member' => array_map($this->normalize(...), $this->repository->findBy([], ['createdAt' => 'DESC']))]); }
    #[Route('/{id<\d+>}/unsubscribe', name: 'todatempo_admin_waitlist_unsubscribe', methods: ['POST'])]
    public function unsubscribe(WaitlistRequest $entry): JsonResponse { $entry->unsubscribe(); $this->entityManager->flush(); return new JsonResponse($this->normalize($entry)); }
    #[Route('/notify', name: 'todatempo_admin_waitlist_notify', methods: ['POST'])]
    public function notify(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true); $data = \is_array($data) ? $data : [];
        try { $start = new \DateTimeImmutable((string) ($data['start'] ?? '')); $end = new \DateTimeImmutable((string) ($data['end'] ?? '')); }
        catch (\Throwable) { return new JsonResponse(['error' => 'Le créneau est invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY); }
        $code = trim((string) ($data['serviceCode'] ?? ''));
        if ($code === '' || $end <= $start) return new JsonResponse(['error' => 'La prestation et le créneau sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        return new JsonResponse(['notified' => $this->notifier->notify($code, $start, $end)]);
    }
    private function normalize(WaitlistRequest $entry): array { return ['id' => $entry->getId(), 'status' => $entry->getStatus(), 'serviceCode' => $entry->getServiceCode(), 'serviceName' => $entry->getServiceName(), 'customerName' => trim($entry->getCustomerFirstName().' '.$entry->getCustomerLastName()), 'customerEmail' => $entry->getCustomerEmail(), 'periodStart' => $entry->getPeriodStart()->format(\DateTimeInterface::ATOM), 'periodEnd' => $entry->getPeriodEnd()->format(\DateTimeInterface::ATOM), 'createdAt' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM)]; }
}
