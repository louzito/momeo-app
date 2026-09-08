<?php

declare(strict_types=1);

namespace App\Team;

use App\Entity\StaffMember;
use App\Entity\User\AdminUser;
use App\Tenant\TenantContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WebsiteMembershipClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private TenantContext $tenantContext,
        #[Autowire('%env(TODATEMPO_WEBSITE_MEMBERSHIP_URL)%')] private string $url,
        #[Autowire('%todatempo.provisioning_secret%')] private string $secret,
    ) {}

    public function sync(AdminUser $account, StaffMember $member): void
    {
        if ($this->url === '' || $this->secret === '') {
            throw new \RuntimeException('La synchronisation des comptes avec le site TodaTempo n’est pas configurée.');
        }

        try {
            // PUT est idempotent : une nouvelle tentative conserve le compte et ses autres espaces.
            $response = $this->httpClient->request('PUT', $this->url, [
                'headers' => ['X-TodaTempo-Provisioning-Key' => $this->secret],
                'json' => [
                    'slug' => $this->tenantContext->getSlug(),
                    'email' => $account->getEmail(),
                    'firstName' => $member->getFirstName(),
                    'lastName' => $member->getLastName(),
                    'role' => $account->getTeamRole()->value,
                    'active' => $account->isEnabled() && $member->isActive(),
                ],
                'timeout' => 10,
                'max_duration' => 15,
                'max_redirects' => 0,
            ]);
            if (!in_array($response->getStatusCode(), [200, 201, 204], true)) {
                throw new \RuntimeException('Membership rejected.');
            }
        } catch (\Throwable) {
            // Ne pas exposer la réponse distante ni les options contenant le secret.
            throw new \RuntimeException('Le site TodaTempo n’a pas confirmé le compte. Réessayez l’enregistrement.');
        }
    }
}
