<?php

declare(strict_types=1);

namespace App\Tests\Availability;

use App\Entity\Booking;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Shared isolated database fixture; every test rolls back its transaction. */
abstract class AvailabilityTestCase extends KernelTestCase
{
    protected ?EntityManagerInterface $entityManager = null;
    protected StaffMember $staff;
    protected string $serviceCode;
    protected string $planningCode;
    protected \DateTimeImmutable $start;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $suffix = bin2hex(random_bytes(6));
        $this->serviceCode = 'contract_'.$suffix;
        $this->planningCode = 'planning_contract_'.$suffix;
        $this->start = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Paris')))->modify('+1 day')->setTime(12, 0);

        $product = new Product();
        $product->setCode($this->serviceCode);
        $product->setEnabled(true);
        $this->entityManager->persist($product);
        $staff = new StaffMember();
        $this->staff = $staff;
        $staff->setFirstName('Contract');
        $staff->setLastName($suffix);
        $staff->setServiceCodes([$this->serviceCode]);
        $staff->setWorkingHours([strtolower($this->start->format('l')) => [['start' => '00:00', 'end' => '23:59']]]);
        $this->entityManager->persist($staff);
        $planning = new Taxon();
        $planning->setCode($this->planningCode);
        $planning->setEnabled(true);
        $planning->getTranslation('en_US')->setName($this->planningCode);
        $planning->getTranslation('en_US')->setSlug($this->planningCode);
        $planning->getTranslation('en_US')->setDescription(json_encode([
            'days' => [$this->start->format('Y-m-d') => ['12:00']], 'jumpCodes' => [$this->serviceCode],
        ], JSON_THROW_ON_ERROR));
        $this->entityManager->persist($planning);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager?->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    protected function planningSlots(string $json): array
    {
        return array_values(array_filter(
            json_decode($json, true, flags: JSON_THROW_ON_ERROR)['member'],
            fn (array $slot): bool => $slot['planningCode'] === $this->planningCode,
        ));
    }

    protected function configureRules(array $rules): void
    {
        $taxon = $this->entityManager->getRepository(Taxon::class)->findOneBy(['code' => 'todatempo_config']);
        if (!$taxon instanceof Taxon) {
            $taxon = new Taxon();
            $taxon->setCode('todatempo_config');
            $taxon->getTranslation('en_US')->setName('Contract configuration');
            $taxon->getTranslation('en_US')->setSlug('contract-configuration');
            $this->entityManager->persist($taxon);
        }
        $taxon->getTranslation('en_US')->setDescription(json_encode(['timezone' => 'Europe/Paris', 'bookingRules' => $rules], JSON_THROW_ON_ERROR));
        $this->entityManager->flush();
    }

    protected function booking(\DateTimeImmutable $start, \DateTimeImmutable $end): Booking
    {
        $booking = new Booking();
        $booking->setReference('CONTRACT-'.bin2hex(random_bytes(5)));
        $booking->setPublicToken(bin2hex(random_bytes(16)));
        $booking->setServiceCode($this->serviceCode);
        $booking->setServiceName('Contract');
        $booking->setPlanningCode($this->planningCode);
        $booking->setCustomerFirstName('Contract');
        $booking->setCustomerLastName('Test');
        $booking->setCustomerEmail('contract@example.test');
        $booking->setSlotStart($start->setTimezone(new \DateTimeZone('UTC')));
        $booking->setSlotEnd($end->setTimezone(new \DateTimeZone('UTC')));

        return $booking;
    }
}
