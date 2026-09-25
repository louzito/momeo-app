<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\DataProvider;

final class BookableResourceContractTest extends KernelTestCase
{
    public function testResourceCapacityIsEnforcedInsideTheTransaction(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('executeStatement')->with(
            'INSERT IGNORE INTO momeo_booking_lock (lock_key) VALUES (?)', ['resource:room'],
        )->willReturn(1);
        $connection->expects(self::exactly(3))->method('fetchOne')->willReturnOnConsecutiveCalls('resource:room', 2, 2);
        $booking = new \App\Entity\Booking();
        $booking->setResourceCode('room');
        $booking->setSlotStart(new \DateTimeImmutable('2026-10-10T12:00:00Z'));
        $booking->setSlotEnd(new \DateTimeImmutable('2026-10-10T13:00:00Z'));
        $this->expectException(\App\Service\Booking\SlotUnavailable::class);
        $this->expectExceptionMessage('La capacité de la ressource');
        (new \App\Service\Booking\BookingSlotGuard($connection))->assertAvailable($booking);
    }

    public function testAdminCrudAndServiceAssociationRoutesExist(): void
    {
        $reflection = new \ReflectionClass(\App\Controller\AdminBookableResourceApiController::class);
        $routes = [];
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes(\Symfony\Component\Routing\Attribute\Route::class) as $attribute) {
                $route = $attribute->newInstance();
                $routes[$method->name] = [$route->getPath(), $route->getMethods()];
            }
        }
        self::assertSame(['/bookable-resources', ['POST']], $routes['create']);
        self::assertSame(['/bookable-resources/{code}', ['DELETE']], $routes['delete']);
        self::assertSame(['/services/{code}/bookable-resources', ['PUT']], $routes['updateService']);
    }

    private EntityManagerInterface $em;

    private function controller(): \App\Controller\AdminBookableResourceApiController
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        return self::getContainer()->get(\App\Controller\AdminBookableResourceApiController::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    private function request(array $payload = []): Request
    {
        return new Request(content: json_encode($payload + [
            'name' => 'Test extraction', 'capacity' => 2,
            'calendar' => ['monday' => [['start' => '09:00', 'end' => '12:00']]],
        ], JSON_THROW_ON_ERROR));
    }

    public static function invalidInputs(): iterable
    {
        yield 'capacity' => [['capacity' => 0], 'La capacité doit être supérieure ou égale à 1.'];
        yield 'empty calendar' => [['calendar' => []], 'Au moins une plage de disponibilité est obligatoire.'];
        yield 'reversed range' => [['calendar' => ['monday' => [['start' => '12:00', 'end' => '09:00']]]], 'L’heure de fin doit être après l’heure de début.'];
        yield 'type' => [['type' => 'invalid'], 'Le type de ressource est invalide.'];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidCreateAndUpdateLeaveStoredStateIntact(array $invalid, string $message): void
    {
        $controller = $this->controller();
        $code = 'test_'.bin2hex(random_bytes(8));
        $response = $controller->create($this->request(['code' => $code] + $invalid));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => $message], json_decode($response->getContent(), true));
        $this->em->flush();
        self::assertNull($this->em->getRepository(\App\Entity\BookableResource::class)->findOneBy(['code' => $code]));
        self::assertSame(201, $controller->create($this->request(['code' => $code]))->getStatusCode());
        $response = $controller->update($code, $this->request($invalid));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => $message], json_decode($response->getContent(), true));
        $this->em->flush();
        $this->em->clear();
        $stored = $this->em->getRepository(\App\Entity\BookableResource::class)->findOneBy(['code' => $code]);
        self::assertSame(2, $stored->getCapacity());
        self::assertSame('Test extraction', $stored->getName());
    }

    public function testCrudPreservesPayloadsCodesAndMissingResponses(): void
    {
        $controller = $this->controller();
        $suffix = bin2hex(random_bytes(8));
        $response = $controller->create($this->request(['name' => 'Salle '.$suffix]));
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $code = 'resource_salle_'.$suffix;
        self::assertSame($code, $data['code']);
        self::assertSame(2, $data['capacity']);
        self::assertSame(['monday' => [['start' => '09:00', 'end' => '12:00']]], $data['calendar']);
        self::assertTrue($data['active']);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
        self::assertContains($data, json_decode($controller->index()->getContent(), true)['member']);
        $duplicate = $controller->create($this->request(['code' => $code]));
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertSame(['error' => 'Le code de la ressource existe déjà ou est invalide.'], json_decode($duplicate->getContent(), true));
        $updated = $controller->update($code, $this->request(['code' => 'ignored', 'capacity' => 3]));
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame($code, json_decode($updated->getContent(), true)['code']);
        self::assertSame(3, json_decode($updated->getContent(), true)['capacity']);
        self::assertSame(204, $controller->delete($code)->getStatusCode());
        foreach ([$controller->delete($code), $controller->update($code, $this->request())] as $missing) {
            self::assertSame(404, $missing->getStatusCode());
            self::assertSame(['error' => 'Ressource introuvable.'], json_decode($missing->getContent(), true));
        }
    }

    public function testProductAssignmentValidationAndCalculatedAvailability(): void
    {
        $controller = $this->controller();
        $code = 'resource_'.bin2hex(random_bytes(8));
        $controller->create($this->request(['code' => $code]));
        $product = new \App\Entity\Product\Product();
        $product->setCode('service_'.bin2hex(random_bytes(8)));
        $this->em->persist($product);
        $this->em->flush();
        $serviceCode = $product->getCode();
        foreach ([
            [['codes' => [], 'required' => true], 'Une ressource obligatoire doit avoir au moins une ressource compatible.'],
            [['codes' => ['missing_resource']], 'La ressource « missing_resource » est introuvable.'],
        ] as [$payload, $error]) {
            $response = $controller->updateService($serviceCode, $this->request($payload));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['error' => $error], json_decode($response->getContent(), true));
            self::assertSame([], $product->getBookableResourceCodes());
            self::assertFalse($product->isBookableResourceRequired());
        }
        $response = $controller->updateService($serviceCode, $this->request(['codes' => [$code, $code, ''], 'required' => true]));
        self::assertSame(200, $response->getStatusCode());
        $expected = ['codes' => [$code], 'required' => true];
        self::assertSame($expected, json_decode($response->getContent(), true));
        self::assertSame($expected, json_decode($controller->service($serviceCode)->getContent(), true));
        $this->em->refresh($product);
        self::assertSame([$code], $product->getBookableResourceCodes());
        self::assertTrue($product->isBookableResourceRequired());
        foreach ([$controller->service('missing_service'), $controller->updateService('missing_service', $this->request())] as $missing) {
            self::assertSame(404, $missing->getStatusCode());
            self::assertSame(['error' => 'Prestation introuvable.'], json_decode($missing->getContent(), true));
        }

        $zone = self::getContainer()->get(\App\Service\Availability\CenterTimeZoneProvider::class)->get();
        $start = new \DateTimeImmutable('2030-06-10 10:00', $zone); // Monday
        $end = $start->modify('+1 hour');
        $availability = self::getContainer()->get(\App\Service\Resource\ResourceAvailability::class);
        $booking = new \App\Entity\Booking();
        $booking->setResourceCode($code);
        $booking->setSlotStart($start);
        $booking->setSlotEnd($end);
        self::assertSame([$code], $availability->availableCodes($product, $start, $end, [$booking]));
        self::assertSame([], $availability->availableCodes($product, $start, $end, [$booking, clone $booking]));
        $controller->update($code, $this->request(['capacity' => 1]));
        self::assertSame([], $availability->availableCodes($product, $start, $end, [$booking]));
        self::assertSame([$code], $availability->availableCodes($product, $start, $end, []));
    }

    public function testDeletingUsedResourceArchivesItWithoutRemovingIt(): void
    {
        $this->controller();
        $resource = new \App\Entity\BookableResource();
        $resource->setCode('used_room');
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects(self::once())->method('fetchOne')
            ->with('SELECT COUNT(*) FROM momeo_booking WHERE resource_code = ?', ['used_room'])->willReturn(1);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects(self::never())->method('remove');
        $em->expects(self::once())->method('flush');
        $service = new \App\Service\Resource\BookableResourceManagementService(
            self::getContainer()->get(\App\Repository\BookableResourceRepository::class),
            new \App\Service\Planning\PlanningInput(), $em,
        );
        $service->delete($resource);
        self::assertFalse($resource->isActive());
    }
}
