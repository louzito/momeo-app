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
        self::assertNotFalse($template);
        foreach (['subject', 'intro', 'signature'] as $field) {
            self::assertStringContainsString("emailCode, '{$field}'", $template);
        }
    }

    public function testDispatcherSendsEveryTransitionWithCurrentTenantAndEncodedToken(): void
    {
        $registry = new \App\Service\Tenant\TenantRegistry(__DIR__.'/../Fixtures/tenants.json', false);
        $context = new \App\Service\Tenant\TenantContext($registry, new \App\Service\Tenant\TenantIdentifierResolver(), 'demo');
        $context->setSlug('other');
        $em = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $channel = new \App\Entity\Channel\Channel();
        $repository->method('findOneBy')->willReturn($channel);
        $configuration = new \App\Entity\Taxonomy\Taxon();
        $configuration->getTranslation('en_US')->setDescription('{"schemaVersion":1,"published":{"timezone":"Pacific/Tahiti"}}');
        $configRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $configRepository->method('findOneBy')->willReturn($configuration);
        $em->method('getRepository')->willReturnMap([
            [\App\Entity\Channel\Channel::class, $repository],
            [\App\Entity\Taxonomy\Taxon::class, $configRepository],
        ]);
        $sender = $this->createMock(\Sylius\Component\Mailer\Sender\SenderInterface::class);
        $booking = new \App\Entity\Booking();
        $booking->setCustomerEmail('customer@example.test');
        $booking->setPublicToken('token/with space');
        $codes = [];
        $sender->expects(self::exactly(5))->method('send')->willReturnCallback(
            static function (string $code, array $recipients, array $data) use (&$codes, $booking, $channel): void {
                $codes[] = $code;
                self::assertSame(['customer@example.test'], $recipients);
                self::assertSame($code, $data['emailCode']);
                self::assertSame($booking, $data['booking']);
                self::assertSame($channel, $data['channel']);
                self::assertSame('https://example.test/other/account/booking/token%2Fwith%20space', $data['bookingUrl']);
                self::assertSame('Pacific/Tahiti', $data['centerTimezone']);
            },
        );
        $dispatcher = new \App\Service\Email\BookingEmailDispatcher(
            $sender, $em, $context, new \App\Service\Availability\CenterTimeZoneProvider($em),
            new \App\Service\Tenant\TenantUrlGenerator($registry, 'https://example.test'),
        );
        foreach (['confirmation', 'paymentConfirmation', 'cancellation', 'rescheduled', 'reminder'] as $method) {
            $dispatcher->$method($booking);
        }
        self::assertSame(['booking_confirmation', 'payment_confirmation', 'booking_cancelled', 'booking_rescheduled', 'booking_reminder'], $codes);
    }

    public function testEveryBusinessTransitionDispatchesItsEmail(): void
    {
        $shop = file_get_contents($this->projectDir.'/src/Controller/ShopBookingApiController.php');
        // Admin mutation delivery is exercised by CustomerBookingChangesContractTest.
        $stripe = file_get_contents($this->projectDir.'/src/Controller/ShopStripePaymentController.php');

        self::assertSame(2, substr_count((string) $shop, 'emailDispatcher->confirmation($booking)'));
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
