<?php

declare(strict_types=1);

namespace App\Email;

use App\Entity\Booking;
use App\Entity\Channel\Channel;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BookingEmailDispatcher
{
    public function __construct(
        private readonly SenderInterface $sender,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        #[Autowire('%todatempo.public_base_url%')] private readonly string $publicBaseUrl,
    ) {
    }

    public function confirmation(Booking $booking): void
    {
        $this->send('booking_confirmation', $booking);
    }

    public function paymentConfirmation(Booking $booking): void
    {
        $this->send('payment_confirmation', $booking);
    }

    public function cancellation(Booking $booking): void
    {
        $this->send('booking_cancelled', $booking);
    }

    public function rescheduled(Booking $booking): void
    {
        $this->send('booking_rescheduled', $booking);
    }

    private function send(string $type, Booking $booking): void
    {
        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        if (!$channel instanceof Channel) {
            throw new \RuntimeException('Canal TodaTempo introuvable pour l’envoi de l’email transactionnel.');
        }

        $this->sender->send($type, [$booking->getCustomerEmail()], [
            'emailCode' => $type,
            'booking' => $booking,
            'channel' => $channel,
            'bookingUrl' => sprintf('%s/%s/account/booking/%s', rtrim($this->publicBaseUrl, '/'), rawurlencode($this->tenantContext->getSlug()), rawurlencode($booking->getPublicToken())),
        ]);
    }
}
