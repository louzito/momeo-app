<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class InvoiceSecurityContractTest extends TestCase
{
    public function testPdfIsGeneratedOnCompletedPaymentBeforePluginEmail(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/EventListener/CreateInvoiceOnPaymentCompletedListener.php');
        self::assertIsString($source);
        self::assertStringContainsString("workflow.sylius_payment.completed.complete", $source);
        self::assertStringContainsString('priority: 100', $source);
        self::assertStringContainsString('invoiceFileProvider->provide', $source);
    }

    public function testCustomerDownloadChecksOwnershipAndPaidState(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Controller/ShopCustomerAccountApiController.php');
        self::assertIsString($source);
        self::assertStringContainsString("#[IsGranted('ROLE_USER')]", $source);
        self::assertStringContainsString('ownsInvoice($invoice, $user)', $source);
        self::assertStringContainsString("paymentState() === 'paid'", $source);
        self::assertStringContainsString("'Cache-Control' => 'private, no-store, max-age=0'", $source);
    }

    public function testPdfAdapterHasNoImplicitDockerService(): void
    {
        $config = file_get_contents(__DIR__.'/../../config/packages/sylius_pdf_generation.yaml');
        $compose = file_get_contents(__DIR__.'/../../compose.override.yml');
        self::assertIsString($config);
        self::assertIsString($compose);
        self::assertStringContainsString('adapter: knp_snappy', $config);
        self::assertStringNotContainsString('adapter: gotenberg', $config);
        self::assertStringNotContainsString("\n    gotenberg:", $compose);
    }
}
