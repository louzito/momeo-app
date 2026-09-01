<?php

declare(strict_types=1);

namespace App\GiftVoucher;

use App\Entity\GiftVoucher;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;

/**
 * Envoie l'email `gift_voucher` (code + QR) au beneficiaire ET a l'acheteur
 * (deux envois separes — pas de CC, chacun ne voit pas l'email de l'autre).
 * Meme mecanique "email editable par centre" que order_confirmation /
 * invoice_generated : voir config/packages/skybook_mailer.yaml (code ->
 * template) et templates/email/gift_voucher.html.twig (skybook_email_text,
 * App\Twig\SkybookEmailExtension).
 */
final class GiftVoucherMailer
{
    public function __construct(
        private readonly SenderInterface $emailSender,
        private readonly GiftVoucherQrCodeGenerator $qrCodeGenerator,
    ) {
    }

    public function sendActivationEmail(GiftVoucher $voucher, ChannelInterface $channel): void
    {
        $data = [
            'voucher' => $voucher,
            'channel' => $channel,
            'qrDataUri' => $this->qrCodeGenerator->generateDataUri($voucher->getCode()),
            'activationUrl' => $this->qrCodeGenerator->activationUrl($voucher->getCode()),
        ];

        $recipients = array_unique(array_filter([$voucher->getBeneficiaryEmail(), $voucher->getPurchaserEmail()]));
        foreach ($recipients as $recipient) {
            $this->emailSender->send('gift_voucher', [$recipient], $data);
        }
    }
}
