<?php

declare(strict_types=1);

namespace App\Service\Waitlist;

use App\Entity\Product\Product;
use App\Entity\WaitlistRequest;
use App\Repository\WaitlistRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Subscription operations on the current tenant's entity manager. */
final readonly class WaitlistManagement
{
    public function __construct(private EntityManagerInterface $entityManager, private WaitlistRequestRepository $repository) {}

    public function subscribe(string $serviceCode, string $firstName, string $lastName, string $email, \DateTimeImmutable $start, \DateTimeImmutable $end): ?WaitlistRequest
    {
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $serviceCode]);
        if (!$product instanceof Product || !$product->isEnabled()) {
            return null;
        }
        $entry = new WaitlistRequest();
        $entry->setServiceCode($serviceCode);
        $entry->setServiceName(mb_substr(trim((string) $product->getName()) ?: $serviceCode, 0, 255));
        $entry->setCustomerFirstName($firstName);
        $entry->setCustomerLastName($lastName);
        $entry->setCustomerEmail($email);
        $entry->setPeriodStart($start);
        $entry->setPeriodEnd($end);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<WaitlistRequest> */
    public function list(): array
    {
        return $this->repository->findBy([], ['createdAt' => 'DESC']);
    }

    public function unsubscribeByToken(string $token): ?WaitlistRequest
    {
        $entry = $this->repository->findOneBy(['unsubscribeToken' => $token]);
        if (!$entry instanceof WaitlistRequest) {
            return null;
        }
        $this->unsubscribe($entry);

        return $entry;
    }

    public function unsubscribe(WaitlistRequest $entry): void
    {
        $entry->unsubscribe();
        $this->entityManager->flush();
    }
}
