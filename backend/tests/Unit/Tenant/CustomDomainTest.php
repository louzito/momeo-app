<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenant;

use App\Tenant\CaddyConfigDumper;
use App\Tenant\CustomDomainManager;
use App\Tenant\DomainName;
use App\Tenant\DomainOwnershipVerifier;
use App\Tenant\TenantRegistry;
use App\Tenant\TenantRegistryWriter;
use App\Tenant\TenantUrlGenerator;
use PHPUnit\Framework\TestCase;

final class CustomDomainTest extends TestCase
{
    private string $directory;
    private string $registryFile;
    private TenantRegistry $registry;
    private TenantRegistryWriter $writer;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/todatempo-domain-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/config', 0777, true);
        $this->registryFile = $this->directory.'/config/tenants.json';
        file_put_contents($this->registryFile, json_encode([
            'alpha' => ['db' => 'db_alpha', 'status' => 'active'],
            'beta' => ['db' => 'db_beta', 'status' => 'active'],
        ], JSON_THROW_ON_ERROR));
        $this->registry = new TenantRegistry($this->registryFile, false);
        $dumper = new CaddyConfigDumper($this->registry, $this->directory, 'alpha', 'https://app.todatempo.test');
        $this->writer = new TenantRegistryWriter($this->registryFile, $dumper);
    }

    public function testDomainIsNormalizedAndCannotBeClaimedByAnotherTenant(): void
    {
        self::assertSame('booking.example.org', DomainName::normalize(' Booking.Example.Org. '));
        $this->writer->assignCustomDomain('alpha', 'booking.example.org', 'first');

        $this->expectException(\DomainException::class);
        $this->writer->assignCustomDomain('beta', 'booking.example.org', 'second');
    }

    public function testOnlyDnsVerifiedDomainBecomesCanonicalAndRoutable(): void
    {
        $verifier = new class extends DomainOwnershipVerifier {
            public function isVerified(string $domain, string $token): bool
            {
                return $domain === 'booking.example.org' && $token !== '';
            }
        };
        $manager = new CustomDomainManager($this->registry, $this->writer, $verifier, 'https://app.todatempo.test');
        $manager->request('alpha', 'booking.example.org');

        $urls = new TenantUrlGenerator($this->registry, 'https://app.todatempo.test');
        self::assertSame('https://app.todatempo.test/alpha/', $urls->url('alpha'));
        self::assertTrue($manager->verify('alpha'));
        self::assertSame('alpha', $this->registry->slugForVerifiedDomain('booking.example.org'));
        self::assertSame('https://booking.example.org/alpha/account', $urls->url('alpha', 'account'));

        $caddy = file_get_contents($this->directory.'/caddy/Caddyfile');
        self::assertStringContainsString('@unknown_host not host app.todatempo.test booking.example.org', $caddy);
        self::assertStringContainsString('redir @booking_example_org_root /alpha/ 308', $caddy);
        self::assertStringContainsString('@booking_example_org_wrong_tenant', $caddy);
    }
}
