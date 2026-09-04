<?php

declare(strict_types=1);

namespace App\Waitlist;

use App\Availability\CenterTimeZoneProvider;
use App\Entity\Channel\Channel;
use App\Entity\WaitlistNotification;
use App\Entity\WaitlistRequest;
use App\Repository\WaitlistRequestRepository;
use App\Tenant\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class WaitlistNotifier
{
    public function __construct(
        private readonly WaitlistRequestRepository $requests,
        private readonly EntityManagerInterface $entityManager,
        private readonly SenderInterface $sender,
        private readonly TenantContext $tenantContext,
        private readonly CenterTimeZoneProvider $timeZoneProvider,
        #[Autowire('%todatempo.public_base_url%')] private readonly string $publicBaseUrl,
    ) {}

    /** Notify matching subscribers once for this exact slot. No booking is created. */
    public function notify(string $serviceCode, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $sent = 0;
        foreach ($this->requests->findActiveMatching($serviceCode, $start, $end) as $request) {
            $delivery = new WaitlistNotification($request, $start, $end);
            if ($this->entityManager->getRepository(WaitlistNotification::class)->findOneBy(['idempotencyKey' => $delivery->getIdempotencyKey()]) instanceof WaitlistNotification) {
                continue;
            }
            try {
                $this->entityManager->persist($delivery);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // Un worker concurrent a réservé le même envoi.
                return $sent;
            }

            try {
                $this->send($request, $start, $end);
                $delivery->markSent();
                $this->entityManager->flush();
                ++$sent;
            } catch (\Throwable $exception) {
                $this->entityManager->remove($delivery);
                $this->entityManager->flush();
                throw $exception;
            }
        }
        return $sent;
    }

    private function send(WaitlistRequest $request, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        if (!$channel instanceof Channel) throw new \RuntimeException('Canal TodaTempo introuvable.');
        $base = rtrim($this->publicBaseUrl, '/').'/'.rawurlencode($this->tenantContext->getSlug());
        $this->sender->send('waitlist_availability', [$request->getCustomerEmail()], [
            'request' => $request, 'slotStart' => $start, 'slotEnd' => $end, 'channel' => $channel,
            'bookingUrl' => $base.'/services/'.rawurlencode($request->getServiceCode()),
            'unsubscribeUrl' => $base.'/waitlist/unsubscribe/'.$request->getUnsubscribeToken(),
            'centerTimezone' => $this->timeZoneProvider->get()->getName(),
        ]);
    }
}
