<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminStaffMemberApiController;
use App\Controller\AdminStaffTimeOffApiController;
use App\Entity\StaffMember;
use App\Entity\StaffTimeOff;
use App\Entity\User\AdminUser;
use App\Repository\StaffMemberRepository;
use App\Service\Security\TeamRole;
use App\Service\Staff\StaffAccountService;
use App\Service\Staff\StaffManagementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/** Real Doctrine writes in a disposable test tenant, rolled back after each test. */
final class StaffManagementContractTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StaffMember $member;
    private AdminUser $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        // Make the last-owner cases independent of the disposable tenant's seed data.
        foreach ($this->em->getRepository(AdminUser::class)->findAll() as $account) {
            $account->setTeamRole(TeamRole::Practitioner);
        }
        $this->member = new StaffMember();
        $this->member->setFirstName('Original');
        $this->member->setLastName('Name');
        $this->em->persist($this->member);
        $this->owner = $this->account(TeamRole::Owner, $this->member);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    private function account(TeamRole $role, ?StaffMember $member = null): AdminUser
    {
        $account = new AdminUser();
        $email = bin2hex(random_bytes(8)).'@example.test';
        $account->setEmail($email);
        $account->setUsername($email);
        $account->setPassword('not-a-login-password');
        $account->setLocaleCode('en_US');
        $account->setEnabled(true);
        $account->setTeamRole($role);
        $account->setStaffMember($member);
        $this->em->persist($account);

        return $account;
    }

    private function controller(TeamRole $viewer = TeamRole::Owner, ?EntityManagerInterface $em = null): AdminStaffMemberApiController
    {
        $em ??= $this->em;
        $user = new AdminUser();
        $user->setTeamRole($viewer);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $accounts = new StaffAccountService($em);

        return new AdminStaffMemberApiController(
            self::getContainer()->get(StaffMemberRepository::class),
            new StaffManagementService($em, $accounts), $accounts, $security,
        );
    }

    private function request(array $payload = []): Request
    {
        return new Request(content: json_encode($payload + ['firstName' => 'Changed', 'lastName' => 'Name'], JSON_THROW_ON_ERROR));
    }

    public static function invalidChanges(): iterable
    {
        yield 'missing name' => [['firstName' => ''], 'Le prénom et le nom sont obligatoires.'];
        yield 'invalid personal email' => [['email' => 'invalid'], 'L’adresse email est invalide.'];
        yield 'invalid color' => [['color' => 'red'], 'La couleur doit être au format #RRGGBB.'];
        yield 'hours after personal fields' => [['workingHours' => ['monday' => [['start' => '12:00', 'end' => '11:00']]]], 'L’heure de fin doit être postérieure à l’heure de début.'];
        yield 'missing account' => [['accountEmail' => 'missing@example.test'], 'Aucun compte actif ne correspond à cette adresse email.'];
        yield 'invalid role' => [['role' => 'superadmin'], 'Le rôle doit être owner, manager, reception ou practitioner.'];
        yield 'unlink last owner' => [['accountEmail' => ''], 'Le dernier propriétaire ne peut pas être dissocié.'];
    }

    #[DataProvider('invalidChanges')]
    public function testRejectedUpdateDoesNotDirtyManagedEntities(array $payload, string $error): void
    {
        $response = $this->controller()->update($this->member, $this->request($payload));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['error' => $error], json_decode($response->getContent(), true));
        self::assertSame('Original', $this->member->getFirstName());
        self::assertSame($this->member, $this->owner->getStaffMember());
        $this->em->flush(); // A later unrelated flush must not save the rejected draft.
        $this->em->refresh($this->member);
        self::assertSame('Original', $this->member->getFirstName());
    }

    public function testLastOwnerCannotBeDemotedOrReplaced(): void
    {
        $other = $this->account(TeamRole::Practitioner);
        $this->em->flush();
        foreach ([[$this->owner, 'Au moins un propriétaire actif est obligatoire.'], [$other, 'Le dernier propriétaire ne peut pas être remplacé.']] as [$account, $error]) {
            $response = $this->controller()->update($this->member, $this->request(['accountEmail' => $account->getEmail(), 'role' => 'manager']));
            self::assertSame(422, $response->getStatusCode());
            self::assertSame(['error' => $error], json_decode($response->getContent(), true));
            self::assertSame('Original', $this->member->getFirstName());
            self::assertSame(TeamRole::Owner, $this->owner->getTeamRole());
        }
    }

    public function testInvalidDemotionDoesNotUnlinkTheOtherAccount(): void
    {
        $staff = new StaffMember();
        $staff->setFirstName('Other');
        $staff->setLastName('Member');
        $this->em->persist($staff);
        $linked = $this->account(TeamRole::Practitioner, $staff);
        $this->em->flush();
        $response = $this->controller()->update($staff, $this->request(['accountEmail' => $this->owner->getEmail(), 'role' => 'manager']));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame($staff, $linked->getStaffMember());
        self::assertSame('Other', $staff->getFirstName());
        $this->em->flush();
        $this->em->refresh($linked);
        self::assertSame($staff, $linked->getStaffMember());
    }

    public function testInvalidCreateAndDisabledAccountLeaveNoScheduledInsert(): void
    {
        $disabled = $this->account(TeamRole::Practitioner);
        $disabled->setEnabled(false);
        $this->em->flush();
        $before = $this->em->getRepository(StaffMember::class)->count([]);
        $response = $this->controller()->create($this->request(['accountEmail' => $disabled->getEmail()]));
        self::assertSame(422, $response->getStatusCode());
        $this->em->flush();
        self::assertSame($before, $this->em->getRepository(StaffMember::class)->count([]));
    }

    public function testCreationLinksExistingAccountAndKeepsLunchBreak(): void
    {
        $account = $this->account(TeamRole::Reception);
        $this->em->flush();
        $hours = ['monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']]];
        $response = $this->controller()->create($this->request(['accountEmail' => strtoupper($account->getEmail()), 'role' => 'manager', 'workingHours' => $hours]));
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame($hours['monday'], $data['workingHours']['monday']);
        self::assertSame($account->getEmail(), $data['accountEmail']);
        self::assertSame('manager', $data['role']);
        self::assertSame($data['id'], $account->getStaffMember()->getId());
        self::assertSame(TeamRole::Manager, $account->getTeamRole());
    }

    public function testReplacementAndUnlinkWithAnotherActiveOwner(): void
    {
        $this->account(TeamRole::Owner);
        $replacement = $this->account(TeamRole::Reception);
        $this->em->flush();
        $response = $this->controller()->update($this->member, $this->request(['accountEmail' => $replacement->getEmail(), 'role' => 'reception']));
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->owner->getStaffMember());
        self::assertSame($this->member, $replacement->getStaffMember());
        $response = $this->controller()->update($this->member, $this->request(['accountEmail' => '']));
        self::assertSame(200, $response->getStatusCode());
        self::assertNull($replacement->getStaffMember());
    }

    public function testFailureAfterUnlinkFlushRollsBackBothEntities(): void
    {
        $this->account(TeamRole::Owner);
        $replacement = $this->account(TeamRole::Reception);
        $this->em->flush();
        $memberId = $this->member->getId();
        $ownerId = $this->owner->getId();
        $replacementId = $replacement->getId();
        $em = $this->createMock(EntityManagerInterface::class);
        foreach (['getConnection', 'createQueryBuilder', 'getRepository', 'isOpen', 'clear'] as $method) {
            $em->method($method)->willReturnCallback(fn (...$args) => $this->em->$method(...$args));
        }
        $flushes = 0;
        $em->method('flush')->willReturnCallback(function () use (&$flushes): void {
            if (++$flushes === 2) throw new \RuntimeException('Injected flush failure');
            $this->em->flush();
        });
        try {
            $this->controller(em: $em)->update($this->member, $this->request(['accountEmail' => $replacement->getEmail(), 'role' => 'manager']));
            self::fail('Expected storage failure');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected flush failure', $e->getMessage());
        }
        self::assertSame(2, $flushes);
        $this->em->flush();
        self::assertSame('Original', $this->em->find(StaffMember::class, $memberId)->getFirstName());
        self::assertSame($memberId, $this->em->find(AdminUser::class, $ownerId)->getStaffMember()->getId());
        self::assertNull($this->em->find(AdminUser::class, $replacementId)->getStaffMember());
        self::assertSame(TeamRole::Reception, $this->em->find(AdminUser::class, $replacementId)->getTeamRole());
    }

    public function testFailedCreateDiscardsScheduledMemberAndAccountChanges(): void
    {
        $account = $this->account(TeamRole::Reception);
        $this->em->flush();
        $accountId = $account->getId();
        $before = $this->em->getRepository(StaffMember::class)->count([]);
        $em = $this->createMock(EntityManagerInterface::class);
        foreach (['getConnection', 'createQueryBuilder', 'getRepository', 'persist', 'isOpen', 'clear'] as $method) {
            $em->method($method)->willReturnCallback(fn (...$args) => $this->em->$method(...$args));
        }
        $em->method('flush')->willThrowException(new \RuntimeException('Injected create failure'));
        try {
            $this->controller(em: $em)->create($this->request(['accountEmail' => $account->getEmail(), 'role' => 'manager']));
            self::fail('Expected storage failure');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected create failure', $e->getMessage());
        }
        $this->em->flush();
        self::assertSame($before, $this->em->getRepository(StaffMember::class)->count([]));
        $account = $this->em->find(AdminUser::class, $accountId);
        self::assertNull($account->getStaffMember());
        self::assertSame(TeamRole::Reception, $account->getTeamRole());
    }

    public function testDisabledOwnerDoesNotPermitDemotion(): void
    {
        $disabled = $this->account(TeamRole::Owner);
        $disabled->setEnabled(false);
        $this->em->flush();
        $response = $this->controller()->update($this->member, $this->request(['accountEmail' => $this->owner->getEmail(), 'role' => 'practitioner']));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(TeamRole::Owner, $this->owner->getTeamRole());
    }

    public function testArchiveKeepsAccountAndReadHidesAccountForOtherRoles(): void
    {
        self::assertSame(204, $this->controller()->archive($this->member)->getStatusCode());
        $this->em->refresh($this->member);
        self::assertFalse($this->member->isActive());
        self::assertFalse($this->member->isBookable());
        self::assertSame($this->member, $this->owner->getStaffMember());
        foreach (TeamRole::cases() as $role) {
            $data = json_decode($this->controller($role)->index()->getContent(), true)['member'];
            $row = array_values(array_filter($data, fn ($row) => $row['id'] === $this->member->getId()))[0];
            self::assertSame($role === TeamRole::Owner ? $this->owner->getEmail() : null, $row['accountEmail']);
            self::assertSame($role === TeamRole::Owner ? 'owner' : null, $row['role']);
            self::assertArrayNotHasKey('password', $row);
        }
    }

    public function testTimeOffValidationCreationListingAndDeletion(): void
    {
        $controller = self::getContainer()->get(AdminStaffTimeOffApiController::class);
        $payload = ['staffMemberId' => $this->member->getId(), 'start' => '2030-06-10T12:00:00+00:00', 'end' => '2030-06-10T14:00:00+00:00', 'reason' => ' Pause '];
        $before = $this->em->getRepository(StaffTimeOff::class)->count([]);
        foreach ([['staffMemberId' => 0], ['start' => 'invalid'], ['end' => $payload['start']]] as $invalid) {
            self::assertSame(422, $controller->create($this->request($invalid + $payload))->getStatusCode());
        }
        $this->em->flush();
        self::assertSame($before, $this->em->getRepository(StaffTimeOff::class)->count([]));
        $response = $controller->create($this->request($payload));
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame('Pause', $data['reason']);
        self::assertSame($payload['start'], $data['start']);
        self::assertSame($payload['end'], $data['end']);
        self::assertSame($this->member->getId(), $data['staffMemberId']);
        $listing = $controller->index(new Request(query: ['from' => '2030-06-10', 'to' => '2030-06-11']));
        self::assertContains($data, json_decode($listing->getContent(), true)['member']);
        self::assertSame(422, $controller->index(new Request(query: ['from' => 'invalid']))->getStatusCode());
        self::assertSame(204, $controller->delete($this->em->find(StaffTimeOff::class, $data['id']))->getStatusCode());
        self::assertNull($this->em->find(StaffTimeOff::class, $data['id']));
    }
}
