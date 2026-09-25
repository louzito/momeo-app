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

    public function testCustomerDownloadRouteRemainsProtected(): void
    {
        // Ownership and paid state are exercised in ShopCustomerAccountSecurityContractTest.
        $class = new \ReflectionClass(\App\Controller\ShopCustomerAccountApiController::class);
        self::assertSame('ROLE_USER', $class->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class)[0]->newInstance()->attribute);
        $route = $class->getMethod('invoice')->getAttributes(\Symfony\Component\Routing\Attribute\Route::class)[0]->newInstance();
        self::assertSame('/invoices/{id}/download', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        self::assertSame('todatempo_api_shop_account_invoice_download', $route->getName());
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
