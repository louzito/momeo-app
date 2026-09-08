<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminStaffMemberApiController;
use App\Entity\StaffMember;
use App\Entity\User\AdminUser;
use App\Repository\StaffMemberRepository;
use App\Security\TeamRole;
use App\Team\WebsiteMembershipClient;
use App\Tenant\TenantContext;
use App\Tenant\TenantIdentifierResolver;
use App\Tenant\TenantRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class AdminStaffAccountTest extends TestCase
{
    public function testCreatingStaffCreatesLocalAccountAndSendsWebsiteMembership(): void
    {
        $created = [];
        $repository = $this->createMock(EntityRepository::class);
        // A new member has no identifier and must never be used as a query parameter.
        $repository->expects(self::once())->method('findOneBy')->with(['email' => 'camille@example.test'])->willReturn(null);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->method('persist')->willReturnCallback(static function ($entity) use (&$created) { $created[] = $entity; });
        $manager->expects(self::once())->method('flush');
        $http = new MockHttpClient(static function ($method, $url, $options) {
            $body = json_decode($options['body'], true);
            self::assertSame('camille@example.test', $body['email']);
            self::assertSame('manager', $body['role']);
            self::assertSame('alpha', $body['slug']);
            return new MockResponse('', ['http_code' => 201]);
        });
        $controller = $this->controller($manager, $http);
        $response = $controller->create($this->request());
        self::assertSame(201, $response->getStatusCode());
        self::assertCount(2, $created);
        self::assertInstanceOf(StaffMember::class, $created[0]);
        self::assertInstanceOf(AdminUser::class, $created[1]);
        self::assertSame($created[0], $created[1]->getStaffMember());
        self::assertSame(TeamRole::Manager, $created[1]->getTeamRole());
        self::assertTrue($created[1]->isEnabled());
    }

    public function testWebsiteFailureDoesNotFlushLocalAccount(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->expects(self::never())->method('flush');
        $controller = $this->controller($manager, new MockHttpClient(new MockResponse('', ['http_code' => 503])));
        self::assertSame(422, $controller->create($this->request())->getStatusCode());
    }

    public function testAccountAlreadyLinkedInSameSpaceCannotBeReassigned(): void
    {
        $account = new AdminUser();
        $account->setEnabled(true);
        $originalMember = new StaffMember();
        $account->setStaffMember($originalMember);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($account);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->expects(self::never())->method('flush');
        $http = new MockHttpClient(static function () { self::fail('No membership should be sent.'); });
        self::assertSame(422, $this->controller($manager, $http)->create($this->request())->getStatusCode());
        self::assertSame($originalMember, $account->getStaffMember());
    }

    private function controller(EntityManagerInterface $manager, MockHttpClient $http): AdminStaffMemberApiController
    {
        $context = new TenantContext(new TenantRegistry('/unused', false), new TenantIdentifierResolver(), 'alpha');
        return new AdminStaffMemberApiController(
            new StaffMemberRepository($this->createMock(ManagerRegistry::class)), $manager, $this->createMock(Security::class),
            new WebsiteMembershipClient($http, $context, 'https://website.test/memberships', 'test-secret'),
        );
    }

    private function request(): Request
    {
        return Request::create('/api/v2/admin/staff-members', 'POST', content: json_encode([
            'firstName' => 'Camille', 'lastName' => 'Martin', 'email' => 'Camille@example.test', 'role' => 'manager',
        ]));
    }
}
