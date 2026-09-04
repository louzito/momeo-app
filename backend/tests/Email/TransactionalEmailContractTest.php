<?php

declare(strict_types=1);

namespace App\Tests\Email;

use App\Twig\SkybookEmailExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class TransactionalEmailContractTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 2);
    }

    public function testEssentialTemplatesHaveGenericTodaTempoDefaults(): void
    {
        $extension = file_get_contents($this->projectDir.'/src/Twig/SkybookEmailExtension.php');
        self::assertNotFalse($extension);

        foreach (['booking_confirmation', 'payment_confirmation', 'booking_cancelled', 'booking_rescheduled', 'booking_reminder', 'gift_voucher', 'invoice_generated'] as $code) {
            self::assertStringContainsString("'{$code}' => [", $extension);
        }

        $emailSources = $extension
            .file_get_contents($this->projectDir.'/templates/email/booking_transactional.html.twig')
            .file_get_contents($this->projectDir.'/templates/email/gift_voucher.html.twig')
            .file_get_contents($this->projectDir.'/templates/bundles/SyliusInvoicingPlugin/admin/invoice/email/invoice_generated.html.twig');
        self::assertDoesNotMatchRegularExpression('/parachut|saut|dans les airs/i', $emailSources);
    }

    public function testDefaultSubjectRenderingReplacesBusinessVariables(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getRepository')->willThrowException(new \RuntimeException('No database in unit test.'));
        $extension = new SkybookEmailExtension($entityManager);

        self::assertSame(
            'Confirmation de votre réservation RDV-42 — Studio TodaTempo',
            $extension->emailText('booking_confirmation', 'subject', [
                '%reservation%' => 'RDV-42',
                '%etablissement%' => 'Studio TodaTempo',
            ]),
        );
    }

    public function testBookingTemplateRendersConfigurableFieldsAndTenantUrl(): void
    {
        $template = file_get_contents($this->projectDir.'/templates/email/booking_transactional.html.twig');
        $dispatcher = file_get_contents($this->projectDir.'/src/Email/BookingEmailDispatcher.php');
        self::assertNotFalse($template);
        self::assertNotFalse($dispatcher);

        foreach (['subject', 'intro', 'signature'] as $field) {
            self::assertStringContainsString("emailCode, '{$field}'", $template);
        }
        self::assertStringContainsString("tenantContext->getSlug()", $dispatcher);
        self::assertStringContainsString('/account/booking/%s', $dispatcher);
    }

    public function testEveryBusinessTransitionDispatchesItsEmail(): void
    {
        $shop = file_get_contents($this->projectDir.'/src/Controller/ShopBookingApiController.php');
        $admin = file_get_contents($this->projectDir.'/src/Controller/AdminBookingApiController.php');
        $stripe = file_get_contents($this->projectDir.'/src/Controller/ShopStripePaymentController.php');

        self::assertSame(2, substr_count((string) $shop, 'emailDispatcher->confirmation($booking)'));
        self::assertStringContainsString('emailDispatcher->confirmation($booking)', (string) $admin);
        self::assertStringContainsString('emailDispatcher->rescheduled($booking)', (string) $admin);
        self::assertStringContainsString('emailDispatcher->cancellation($booking)', (string) $admin);
        self::assertStringContainsString('emailDispatcher->paymentConfirmation($booking)', (string) $stripe);
    }

    public function testMailerDeliveryUsesRetryableMessengerTransport(): void
    {
        $messenger = file_get_contents($this->projectDir.'/config/packages/messenger.yaml');
        $mailer = file_get_contents($this->projectDir.'/config/packages/mailer.yaml');
        self::assertStringContainsString('SendEmailMessage', (string) $messenger);
        self::assertStringContainsString('max_retries: 5', (string) $messenger);
        self::assertStringContainsString('failure_transport: failed', (string) $messenger);
        self::assertStringContainsString('message_bus: messenger.default_bus', (string) $mailer);
    }
}
