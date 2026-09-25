<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminPlanningApiController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\Attribute\Route;

#[CoversClass(AdminPlanningApiController::class)]
final class AdminPlanningApiContractTest extends KernelTestCase
{
    public function testControllerExposesARealCrudRoute(): void
    {
        $class = new \ReflectionClass(AdminPlanningApiController::class);
        $route = $class->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('/api/v2/admin/plannings', $route->getPath());

        foreach ([
            'index' => ['', 'momeo_api_admin_planning_index', ['GET']],
            'show' => ['/{code}', 'momeo_api_admin_planning_show', ['GET']],
            'create' => ['', 'momeo_api_admin_planning_create', ['POST']],
            'update' => ['/{code}', 'momeo_api_admin_planning_update', ['PUT', 'PATCH']],
            'delete' => ['/{code}', 'momeo_api_admin_planning_delete', ['DELETE']],
        ] as $action => $expected) {
            $route = $class->getMethod($action)->getAttributes(Route::class)[0]->newInstance();
            self::assertSame($expected, [$route->getPath(), $route->getName(), $route->getMethods()]);
        }
    }

    private EntityManagerInterface $em;

    private function controller(): \App\Controller\AdminPlanningApiController
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        return self::getContainer()->get(\App\Controller\AdminPlanningApiController::class);
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
            'weeklyDays' => ['monday' => [['start' => '09:00', 'end' => '12:00']]],
        ], JSON_THROW_ON_ERROR));
    }

    public static function invalidInputs(): iterable
    {
        yield 'capacity' => [['capacity' => 0], 'La capacité doit être supérieure ou égale à 1.'];
        yield 'empty calendar' => [['weeklyDays' => []], 'Au moins un jour et une plage horaire sont obligatoires.'];
        yield 'reversed range' => [['weeklyDays' => ['monday' => [['start' => '12:00', 'end' => '09:00']]]], 'L’heure de fin doit être après l’heure de début.'];
        yield 'timezone' => [['timezone' => 'invalid'], 'Le fuseau horaire est invalide.'];
        yield 'staff' => [['staffMemberId' => -1], 'Collaborateur introuvable.'];
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
        self::assertNull($this->em->getRepository(\App\Entity\Planning::class)->findOneBy(['code' => $code]));
        self::assertSame(201, $controller->create($this->request(['code' => $code]))->getStatusCode());
        $response = $controller->update($code, $this->request($invalid));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => $message], json_decode($response->getContent(), true));
        $this->em->flush();
        $this->em->clear();
        $stored = $this->em->getRepository(\App\Entity\Planning::class)->findOneBy(['code' => $code]);
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
        $code = 'planning_salle_'.$suffix;
        self::assertSame($code, $data['code']);
        self::assertSame(2, $data['capacity']);
        self::assertSame(['monday' => [['start' => '09:00', 'end' => '12:00']]], $data['weeklyDays']);
        self::assertTrue($data['active']);
        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('updatedAt', $data);
        self::assertContains($data, json_decode($controller->index()->getContent(), true)['member']);
        $duplicate = $controller->create($this->request(['code' => $code]));
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertSame(['error' => 'Le code du planning existe déjà ou est invalide.'], json_decode($duplicate->getContent(), true));
        $updated = $controller->update($code, $this->request(['code' => 'ignored', 'capacity' => 3]));
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame($code, json_decode($updated->getContent(), true)['code']);
        self::assertSame(3, json_decode($updated->getContent(), true)['capacity']);
        self::assertSame(204, $controller->delete($code)->getStatusCode());
        foreach ([$controller->delete($code), $controller->update($code, $this->request())] as $missing) {
            self::assertSame(404, $missing->getStatusCode());
            self::assertSame(['error' => 'Planning introuvable.'], json_decode($missing->getContent(), true));
        }
    }

    public function testLegacyDaysServiceAliasesAndStaffScopeArePreserved(): void
    {
        $controller = $this->controller();
        $staff = new \App\Entity\StaffMember();
        $staff->setFirstName('Test');
        $staff->setLastName('Planning');
        $this->em->persist($staff);
        $this->em->flush();
        $days = ['2030-06-10' => ['09:00', '11:00']];
        $response = $controller->create($this->request([
            'code' => 'legacy_'.bin2hex(random_bytes(8)),
            'days' => $days, 'jumpCodes' => [' service_a ', 'service_a', 'service_b'],
            'staffMemberId' => $staff->getId(),
        ]));
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame($days, $data['days']);
        self::assertSame(['service_a', 'service_b'], $data['serviceCodes']);
        self::assertSame($data['serviceCodes'], $data['jumpCodes']);
        self::assertSame('staff', $data['scope']);
        self::assertSame($staff->getId(), $data['staffMemberId']);
        self::assertSame($data, json_decode($controller->show($data['code'])->getContent(), true));
        $repository = self::getContainer()->get(\App\Repository\PlanningRepository::class);
        $planning = $repository->findOneBy(['code' => $data['code']]);
        self::assertContains($planning, $repository->findActiveForService('service_a'));
        self::assertNotContains($planning, $repository->findActiveForService('other_service'));
        $controller->update($data['code'], $this->request(['active' => false]));
        self::assertNotContains($planning, $repository->findActiveForService('service_a'));
        $controller->delete($data['code']);
        self::assertSame(404, $controller->show($data['code'])->getStatusCode());
    }
}
