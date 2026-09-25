<?php

declare(strict_types=1);

namespace App\Tests\Waitlist;

use App\Entity\Channel\Channel;
use App\Entity\WaitlistNotification;
use App\Repository\WaitlistRequestRepository;
use App\Service\Availability\CenterTimeZoneProvider;
use App\Service\Tenant\TenantContext;
use App\Service\Tenant\TenantIdentifierResolver;
use App\Service\Tenant\TenantRegistry;
use App\Service\Tenant\TenantUrlGenerator;
use App\Service\Waitlist\WaitlistManagement;
use App\Service\Waitlist\WaitlistNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Sylius\Component\Mailer\Sender\SenderInterface;

final class WaitlistNotifierTest extends \App\Tests\Availability\AvailabilityTestCase
{
    public function testFailureCanBeRetriedWithoutDuplicateAndUnsubscriptionStopsNewSlots(): void
    {
        $this->entityManager->flush();
        $repository = self::getContainer()->get(WaitlistRequestRepository::class);
        $management = new WaitlistManagement($this->entityManager, $repository);
        $entry = $management->subscribe($this->serviceCode, 'Alice', 'Dupont', 'alice@example.test', $this->start, $this->start->modify('+1 day'));
        $other = $management->subscribe($this->serviceCode, 'Bob', 'Dupont', 'bob@example.test', $this->start, $this->start->modify('+1 day'));
        self::assertNotSame($entry->getUnsubscribeToken(), $other->getUnsubscribeToken());
        $management->unsubscribe($other);
        // Use the real tenant repository/persistence; intercept only channel lookup and outbound mail.
        $em = $this->createMock(EntityManagerInterface::class);
        $channelRepository = $this->createMock(EntityRepository::class);
        $channelRepository->method('findOneBy')->willReturn(new Channel());
        $em->method('getRepository')->willReturnCallback(fn (string $class) => $class === Channel::class ? $channelRepository : $this->entityManager->getRepository($class));
        foreach (['persist', 'remove'] as $method) {
            $em->method($method)->willReturnCallback(fn (object $entity) => $this->entityManager->$method($entity));
        }
        $em->method('flush')->willReturnCallback(fn () => $this->entityManager->flush());
        $registry = new TenantRegistry(__DIR__.'/../Fixtures/tenants.json', false);
        $context = new TenantContext($registry, new TenantIdentifierResolver(), 'demo');
        $context->setSlug('other');
        $sender = $this->createMock(SenderInterface::class);
        $attempt = 0;
        $sender->expects(self::exactly(2))->method('send')->willReturnCallback(function (string $code, array $recipients, array $data) use (&$attempt, $entry): void {
            self::assertSame('waitlist_availability', $code);
            self::assertSame(['alice@example.test'], $recipients);
            self::assertSame('https://example.test/other/waitlist/unsubscribe/'.$entry->getUnsubscribeToken(), $data['unsubscribeUrl']);
            self::assertSame('https://example.test/other/services/'.$this->serviceCode, $data['bookingUrl']);
            if (++$attempt === 1) throw new \RuntimeException('provider failure');
        });
        $notifier = new WaitlistNotifier($repository, $em, $sender, $context, new CenterTimeZoneProvider($em), new TenantUrlGenerator($registry, 'https://example.test'));
        $end = $this->start->modify('+1 hour');
        $key = (new WaitlistNotification($entry, $this->start, $end))->getIdempotencyKey();
        try {
            $notifier->notify($this->serviceCode, $this->start, $end);
            self::fail('Provider error must propagate');
        } catch (\RuntimeException $exception) {
            self::assertSame('provider failure', $exception->getMessage());
        }
        self::assertNull($this->entityManager->getRepository(WaitlistNotification::class)->findOneBy(['idempotencyKey' => $key]));
        self::assertSame(1, $notifier->notify($this->serviceCode, $this->start, $end));
        self::assertSame(0, $notifier->notify($this->serviceCode, $this->start, $end));
        $management->unsubscribe($entry);
        self::assertSame(0, $notifier->notify($this->serviceCode, $end, $end->modify('+1 hour')));
    }
}
