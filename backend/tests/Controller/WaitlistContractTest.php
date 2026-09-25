<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminWaitlistApiController;
use App\Controller\ShopWaitlistApiController;
use App\Entity\Product\Product;
use App\Entity\WaitlistRequest;
use App\Repository\WaitlistRequestRepository;
use App\Service\Waitlist\WaitlistManagement;
use Symfony\Component\HttpFoundation\Request;

final class WaitlistContractTest extends \App\Tests\Availability\AvailabilityTestCase
{
    private function shop(): ShopWaitlistApiController
    {
        return new ShopWaitlistApiController(new WaitlistManagement($this->entityManager, self::getContainer()->get(WaitlistRequestRepository::class)));
    }

    private function payload(array $changes = []): Request
    {
        return new Request(content: json_encode(array_replace([
            'consent' => true, 'serviceCode' => $this->serviceCode,
            'firstName' => ' Alice ', 'lastName' => ' Dupont ', 'email' => ' ALICE@EXAMPLE.TEST ',
            'periodStart' => $this->start->format(DATE_ATOM),
            'periodEnd' => $this->start->modify('+1 day')->format(DATE_ATOM),
        ], $changes), JSON_THROW_ON_ERROR));
    }

    public function testExplicitConsentValidationAndUnavailableService(): void
    {
        foreach ([false, null, 1, 'true'] as $consent) {
            $response = $this->shop()->create($this->payload(['consent' => $consent]));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['error' => 'Votre accord explicite est obligatoire.'], json_decode($response->getContent(), true));
        }
        foreach ([['periodStart' => 'invalid'], ['email' => 'invalid'], ['periodEnd' => $this->start->format(DATE_ATOM)]] as $invalid) {
            self::assertSame(422, $this->shop()->create($this->payload($invalid))->getStatusCode());
        }
        $this->entityManager->flush();
        self::assertSame(404, $this->shop()->create($this->payload(['serviceCode' => 'missing']))->getStatusCode());
        $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $this->serviceCode]);
        $product->setEnabled(false);
        self::assertSame(404, $this->shop()->create($this->payload())->getStatusCode());
    }

    public function testSubscriptionListingAndRepeatedUnsubscription(): void
    {
        $this->entityManager->flush();
        $response = $this->shop()->create($this->payload());
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('active', $data['status']);
        self::assertSame('Inscription enregistrée. Aucune réservation ne sera créée automatiquement.', $data['message']);
        $entry = $this->entityManager->find(WaitlistRequest::class, $data['id']);
        self::assertSame('Alice', $entry->getCustomerFirstName());
        self::assertSame('alice@example.test', $entry->getCustomerEmail());
        $admin = self::getContainer()->get(AdminWaitlistApiController::class);
        $rows = json_decode($admin->index()->getContent(), true)['member'];
        $row = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] === $data['id']))[0];
        self::assertSame([
            'id' => $entry->getId(), 'status' => 'active', 'serviceCode' => $this->serviceCode,
            'serviceName' => 'Contract', 'customerName' => 'Alice Dupont', 'customerEmail' => 'alice@example.test',
            'periodStart' => $entry->getPeriodStart()->format(DATE_ATOM), 'periodEnd' => $entry->getPeriodEnd()->format(DATE_ATOM),
            'createdAt' => $entry->getCreatedAt()->format(DATE_ATOM),
        ], $row);
        self::assertSame(404, $this->shop()->unsubscribe(str_repeat('0', 64))->getStatusCode());
        $token = $entry->getUnsubscribeToken();
        self::assertSame(['status' => 'unsubscribed'], json_decode($this->shop()->unsubscribe($token)->getContent(), true));
        $date = $entry->getUnsubscribedAt();
        $this->shop()->unsubscribe($token);
        self::assertSame('unsubscribed', json_decode($admin->unsubscribe($entry)->getContent(), true)['status']);
        self::assertSame($date, $entry->getUnsubscribedAt());
        self::assertSame($token, $entry->getUnsubscribeToken());
        self::assertSame(422, $admin->notify(new Request(content: '{"serviceCode":""}'))->getStatusCode());
    }

    public function testNotificationHasDatabaseEnforcedIdempotency(): void
    {
        $migration = file_get_contents(__DIR__.'/../../migrations/Version20260911000000.php');
        self::assertStringContainsString('UNIQUE INDEX uniq_waitlist_notification_key', $migration);
        self::assertStringContainsString('FOREIGN KEY (request_id)', $migration);
    }
}
