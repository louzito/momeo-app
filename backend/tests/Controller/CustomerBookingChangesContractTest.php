<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class CustomerBookingChangesContractTest extends TestCase
{
    public function testCustomerChangesAreOwnedProtectedAtomicAndNotified(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Controller/ShopCustomerAccountApiController.php');
        self::assertIsString($source);
        self::assertStringContainsString("#[IsGranted('ROLE_USER')]", $source);
        self::assertStringContainsString('ownedBooking($publicToken, $user)', $source);
        self::assertStringContainsString('beginTransaction()', $source);
        self::assertStringContainsString('LockMode::PESSIMISTIC_WRITE', $source);
        self::assertStringContainsString('slotGuard->assertAvailable', $source);
        self::assertStringContainsString('emailDispatcher->cancellation', $source);
        self::assertStringContainsString('emailDispatcher->rescheduled', $source);
        self::assertStringContainsString('recordChange', $source);
    }

    public function testDeadlinePolicyIsEnforcedOnTheServer(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $config = new \App\Entity\Taxonomy\Taxon();
        $config->getTranslation('en_US')->setDescription('null');
        $repository->method('findOneBy')->willReturn($config);
        $em->method('getRepository')->willReturn($repository);
        $policy = new \App\Service\Booking\CustomerBookingChangePolicy($em, new \App\Service\Booking\BookingRules($em));
        $booking = new \App\Entity\Booking();
        $booking->setStatus(\App\Entity\Booking::STATUS_CONFIRMED);
        $booking->setSlotStart(new \DateTimeImmutable('2026-10-10T12:00:00Z'));
        self::assertSame(['cancelHours' => 24, 'rescheduleHours' => 24], $policy->limits());
        $policy->assertAllowed($booking, 'cancel', new \DateTimeImmutable('2026-10-09T11:59:59Z'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Le délai de modification de 24 heure(s)');
        $policy->assertAllowed($booking, 'cancel', new \DateTimeImmutable('2026-10-09T12:00:00Z'));
    }
}
