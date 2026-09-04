<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment;

use App\Entity\StripeWebhookEvent;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StripeWebhookIdempotencyTest extends KernelTestCase
{
    public function testStripeEventCanOnlyBeClaimedOnce(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $eventId = 'evt_test_'.bin2hex(random_bytes(8));

        try {
            $entityManager->persist(new StripeWebhookEvent($eventId, 'checkout.session.completed'));
            $entityManager->flush();
            $entityManager->clear();

            $entityManager->persist(new StripeWebhookEvent($eventId, 'checkout.session.completed'));
            $this->expectException(UniqueConstraintViolationException::class);
            $entityManager->flush();
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM todatempo_stripe_webhook_event WHERE event_id = ?',
                [$eventId],
            );
        }
    }
}
