<?php

declare(strict_types=1);

namespace App\Tests\Team;

use App\Entity\StaffMember;
use App\Entity\User\AdminUser;
use App\Security\TeamRole;
use App\Team\WebsiteMembershipClient;
use App\Tenant\TenantContext;
use App\Tenant\TenantIdentifierResolver;
use App\Tenant\TenantRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebsiteMembershipClientTest extends TestCase
{
    public function testSameAccountHasIndependentRolesAndActivationInTwoSpaces(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function ($method, $url, $options) use (&$requests) {
            self::assertSame('PUT', $method);
            self::assertSame(0, $options['max_redirects']);
            self::assertContains('X-TodaTempo-Provisioning-Key: test-secret', $options['headers']);
            $requests[] = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            return new MockResponse('', ['http_code' => 204]);
        });
        $context = new TenantContext(new TenantRegistry('/unused', false), new TenantIdentifierResolver(), 'alpha');
        $client = new WebsiteMembershipClient($http, $context, 'https://website.test/memberships', 'test-secret');
        $account = new AdminUser();
        $account->setEmail('camille@example.test');
        $account->setEnabled(true);
        $member = new StaffMember();
        $member->setFirstName('Camille');
        $member->setLastName('Martin');
        $member->setActive(true);
        $client->sync($account, $member);
        $context->setSlug('beta');
        $account->setTeamRole(TeamRole::Manager);
        $client->sync($account, $member);
        $member->setActive(false);
        $client->sync($account, $member);

        self::assertSame(['alpha', 'beta', 'beta'], array_column($requests, 'slug'));
        self::assertSame(['practitioner', 'manager', 'manager'], array_column($requests, 'role'));
        self::assertSame([true, true, false], array_column($requests, 'active'));
        self::assertSame(['camille@example.test'], array_values(array_unique(array_column($requests, 'email'))));
        self::assertArrayNotHasKey('password', $requests[0]);
    }

    public function testWebsiteFailureIsNotReportedAsSuccessAndDoesNotExposeItsBody(): void
    {
        $http = new MockHttpClient(new MockResponse('private server details', ['http_code' => 500]));
        $context = new TenantContext(new TenantRegistry('/unused', false), new TenantIdentifierResolver(), 'alpha');
        $client = new WebsiteMembershipClient($http, $context, 'https://website.test/memberships', 'test-secret');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Le site TodaTempo n’a pas confirmé le compte.');
        $client->sync(new AdminUser(), new StaffMember());
    }

    public function testMissingConfigurationPreventsSilentLocalOnlyAccountCreation(): void
    {
        $http = new MockHttpClient(static function () { self::fail('No HTTP request expected.'); });
        $context = new TenantContext(new TenantRegistry('/unused', false), new TenantIdentifierResolver(), 'alpha');
        $client = new WebsiteMembershipClient($http, $context, '', '');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('n’est pas configurée');
        $client->sync(new AdminUser(), new StaffMember());
    }
}
