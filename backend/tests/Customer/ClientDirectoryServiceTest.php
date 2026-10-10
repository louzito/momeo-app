<?php

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Entity\Booking;
use App\Entity\ClientProfile;
use App\Entity\Customer\Customer;
use App\Repository\BookingRepository;
use App\Service\Booking\BookingView;
use App\Service\Customer\ClientDirectoryService;
use App\Service\Customer\ClientProfileService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class ClientDirectoryServiceTest extends TestCase
{
    public function testRegistrationWithoutBookingAppearsAndCanBeEdited(): void
    {
        $customer = $this->customer();
        [$directory, $profiles] = $this->services([$customer]);
        $result = $directory->list('ALICE@EXAMPLE.TEST');
        self::assertCount(1, $result['member']);
        $client = $result['member'][0];
        self::assertSame(1, $result['stats']['total']);
        self::assertSame(1, $result['stats']['newThisMonth']);
        self::assertSame(0, $result['stats']['withUpcoming']);
        self::assertSame(0, $client['bookingCount']);
        self::assertSame([], $client['bookings']);
        self::assertSame([], $client['purchases']);
        self::assertNull($client['firstBookingAt']);
        self::assertSame($customer->getCreatedAt()->format(DATE_ATOM), $client['clientSince']);
        self::assertSame($customer, $directory->findClient($client['id']));
        $updated = $profiles->update($customer, ['firstName' => 'Bob', 'internalNotes' => 'Suivi'], 'admin');
        self::assertSame('Bob', $updated['firstName']);
        self::assertSame('Martin', $updated['lastName']);
        self::assertSame('0600000000', $updated['phone']);
        self::assertSame('Suivi', $updated['internalNotes']);
        self::assertNull($directory->findClient('unknown'));
    }

    public function testRegistrationAndBookingAreMergedWithoutDuplicatingStatistics(): void
    {
        $customer = $this->customer();
        $customer->setCreatedAt(new \DateTime('first day of last month'));
        $booking = new Booking();
        $booking->setReference('TEST');
        $booking->setServiceCode('test');
        $booking->setServiceName('Massage');
        $booking->setCustomerEmail(' ALICE@EXAMPLE.TEST ');
        $booking->setCustomerFirstName('Alice');
        $booking->setCustomerLastName('Martin');
        $booking->setSlotStart(new \DateTimeImmutable('+3 days'));
        $booking->setSlotEnd(new \DateTimeImmutable('+3 days +1 hour'));
        [$directory] = $this->services([$customer], [$booking]);
        $result = $directory->list();
        self::assertCount(1, $result['member']);
        self::assertSame(['total' => 1, 'newThisMonth' => 0, 'withUpcoming' => 1, 'recurring' => 0], $result['stats']);
        $client = $result['member'][0];
        self::assertSame(substr(hash('sha256', 'alice@example.test'), 0, 16), $client['id']);
        self::assertSame(1, $client['bookingCount']);
        self::assertCount(1, $client['bookings']);
        self::assertSame($booking->getCreatedAt()->format(DATE_ATOM), $client['firstBookingAt']);
        self::assertSame($customer->getCreatedAt()->format(DATE_ATOM), $client['clientSince']);
    }

    private function customer(): Customer
    {
        $customer = new Customer();
        $customer->setEmail('alice@example.test');
        $customer->setFirstName('Alice');
        $customer->setLastName('Martin');
        $customer->setPhoneNumber('0600000000');
        return $customer;
    }

    private function services(array $customers, array $bookings = []): array
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $customerRepository = $this->createMock(EntityRepository::class);
        $customerRepository->method('findAll')->willReturn($customers);
        $profileRepository = $this->createMock(EntityRepository::class);
        $profileRepository->method('findAll')->willReturn([]);
        $profileRepository->method('findOneBy')->willReturn(null);
        $em->method('getRepository')->willReturnMap([[Customer::class, $customerRepository], [ClientProfile::class, $profileRepository]]);
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(Booking::class));
        $persister = $this->createMock(BasicEntityPersister::class);
        $persister->method('loadAll')->willReturn($bookings);
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getEntityPersister')->willReturn($persister);
        $em->method('getUnitOfWork')->willReturn($unitOfWork);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $profiles = new ClientProfileService($em);
        return [new ClientDirectoryService(new BookingRepository($registry), $em, $profiles, new BookingView()), $profiles];
    }
}
