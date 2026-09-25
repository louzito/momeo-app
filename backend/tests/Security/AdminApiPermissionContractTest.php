<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\AdminUser;
use App\Security\AdminApiPermissionSubscriber;
use App\Security\TeamRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AdminApiPermissionContractTest extends TestCase
{
    /** @return iterable<string, array{string, string, TeamRole, bool}> */
    public static function accessDecisions(): iterable
    {
        $domains = [
            'bookings' => [TeamRole::Practitioner, true],
            'clients' => [TeamRole::Practitioner, false],
            'payments' => [TeamRole::Reception, false],
            'invoices' => [TeamRole::Reception, false],
            'products' => [TeamRole::Reception, false],
            'channels' => [TeamRole::Manager, false],
            'waitlist' => [TeamRole::Practitioner, true],
        ];
        foreach ($domains as $resource => [$role, $allowed]) {
            yield $resource => ['/api/v2/admin/'.$resource, 'GET', $role, $allowed];
            yield 'owner '.$resource => ['/api/v2/admin/'.$resource, 'POST', TeamRole::Owner, true];
        }
        yield 'staff read' => ['/api/v2/admin/staff-members', 'GET', TeamRole::Practitioner, true];
        yield 'staff write' => ['/api/v2/admin/staff-members/1', 'PATCH', TeamRole::Practitioner, false];
        yield 'manager finances' => ['/api/v2/admin/payments/1', 'GET', TeamRole::Manager, true];
        yield 'manager catalog' => ['/api/v2/admin/products', 'POST', TeamRole::Manager, true];
        yield 'reception clients' => ['/api/v2/admin/clients', 'GET', TeamRole::Reception, true];
        yield 'public token' => ['/api/v2/admin/administrators/token', 'POST', TeamRole::Practitioner, true];
        yield 'public handoff' => ['/api/v2/admin/todatempo/sso/handoff', 'POST', TeamRole::Practitioner, true];
        yield 'shop' => ['/api/v2/shop/products', 'GET', TeamRole::Practitioner, true];
    }

    #[DataProvider('accessDecisions')]
    public function testBusinessPermissionsAtTheRequestBoundary(string $path, string $method, TeamRole $role, bool $allowed): void
    {
        $user = new AdminUser();
        $user->setTeamRole($role);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create($path, $method), HttpKernelInterface::MAIN_REQUEST);

        try {
            (new AdminApiPermissionSubscriber($security))($event);
            self::assertTrue($allowed, 'The request should have been denied.');
        } catch (AccessDeniedHttpException $exception) {
            self::assertFalse($allowed, $exception->getMessage());
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function testMigrationBackfillsAnOwner(): void
    {
        $migration = file_get_contents(__DIR__.'/../../migrations/Version20260907000000.php');
        self::assertStringContainsString("team_role = 'owner'", $migration);
    }
}
