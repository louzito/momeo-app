<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Entity\Booking;
use Doctrine\ORM\EntityManagerInterface;

/** Transactional fixture: run only against the disposable business test database. */
trait CustomerReadFixture
{
    private EntityManagerInterface $em;
    private string $email;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->email = 'client-'.bin2hex(random_bytes(6)).'@example.test';
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    private function bookingFixture(string $email, string $date, string $status = Booking::STATUS_CONFIRMED): Booking
    {
        $booking = new Booking();
        $booking->setPublicToken(bin2hex(random_bytes(16)));
        $booking->setReference('TEST-'.bin2hex(random_bytes(6)));
        $booking->setCustomerEmail($email);
        $booking->setCustomerFirstName('Alice');
        $booking->setCustomerLastName('Martin');
        $booking->setCustomerNotes('Note visible');
        $booking->setServiceCode('test');
        $booking->setServiceName('Massage');
        $booking->setSlotStart(new \DateTimeImmutable($date));
        $booking->setSlotEnd($booking->getSlotStart()->modify('+1 hour'));
        $booking->setStatus($status);
        $booking->setAmount(1200);
        $this->em->persist($booking);
        $this->em->flush();
        return $booking;
    }
}
