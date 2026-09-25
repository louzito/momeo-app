<?php

declare(strict_types=1);

namespace App\Service\Tenant;

use App\Entity\User\AdminUser;
use App\Service\Security\TeamPermissions;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;

/** Coordinates the two single-use exchanges; HTTP cookies remain in the controllers. */
final readonly class AdminSsoSession
{
    public function __construct(
        private AdminLoginTicketStore $ticketStore,
        private EntityManagerInterface $entityManager,
        private JWTTokenManagerInterface $jwtManager,
        private LoggerInterface $logger,
    ) {}

    public function handoff(string $code): string
    {
        return $this->ticketStore->createBrowserSession($this->ticketStore->consume($code));
    }

    /** @return array{token: string, admin: array<string, mixed>} */
    public function authenticate(string $cookie): array
    {
        try {
            $ticket = $this->ticketStore->consumeBrowserSession($cookie);
        } catch (\Throwable $exception) {
            $this->logger->warning('TodaTempo admin browser session rejected.', [
                'exception' => $exception,
            ]);

            throw new AdminSsoRejected('invalid_sso_session', previous: $exception);
        }

        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $ticket['email']]);
        if (!$admin instanceof AdminUser || !$admin->isEnabled()) {
            throw new AdminSsoRejected('admin_not_found');
        }

        return [
            'token' => $this->jwtManager->create($admin),
            'admin' => [
                'email' => $admin->getEmail(),
                'name' => $ticket['name'],
                'role' => $admin->getTeamRole()->value,
                'permissions' => TeamPermissions::forRole($admin->getTeamRole()),
                'staffMemberId' => $admin->getStaffMember()?->getId(),
            ],
        ];
    }
}
