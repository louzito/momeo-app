<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class InvoiceSecurityContractTest extends TestCase
{
    public function testPdfIsGeneratedOnCompletedPaymentBeforePluginEmail(): void
    {
        $invoice = $this->createMock(\Sylius\InvoicingPlugin\Entity\InvoiceInterface::class);
        $repository = $this->createMock(\Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface::class);
        $repository->expects(self::once())->method('findByOrderNumber')->with('ORDER-1')->willReturn([$invoice]);
        $provider = $this->createMock(\Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface::class);
        $provider->expects(self::once())->method('provide')->with($invoice)->willThrowException(new \RuntimeException('pdf boundary'));
        $listener = new \App\EventListener\CreateInvoiceOnPaymentCompletedListener($repository, $provider);
        $attribute = (new \ReflectionClass($listener))->getAttributes(\Symfony\Component\EventDispatcher\Attribute\AsEventListener::class)[0]->newInstance();
        self::assertSame('workflow.sylius_payment.completed.complete', $attribute->event);
        self::assertSame(100, $attribute->priority);
        $payment = $this->createMock(\Sylius\Component\Core\Model\PaymentInterface::class);
        $order = $this->createMock(\Sylius\Component\Core\Model\OrderInterface::class);
        $order->method('getNumber')->willReturn('ORDER-1');
        $payment->method('getOrder')->willReturn($order);
        $this->expectExceptionMessage('pdf boundary');
        $listener(new \Symfony\Component\Workflow\Event\CompletedEvent($payment, new \Symfony\Component\Workflow\Marking()));
    }

    public function testCustomerAccessRejectsOtherAccountUnpaidAndMissingInvoiceBeforePdf(): void
    {
        foreach ([['other@example.test', 'paid'], ['alice@example.test', 'awaiting_payment'], [' alice@example.test ', 'paid'], [null, 'paid']] as [$email, $state]) {
            $invoice = $email === null ? null : $this->invoice($email, $state);
            $repository = $this->createMock(\Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface::class);
            $repository->expects(self::once())->method('find')->with('invoice-1')->willReturn($invoice);
            $provider = $this->createMock(\Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface::class);
            $provider->expects(self::never())->method('provide');
            $access = new \App\Service\Invoice\InvoiceAccess($repository, $provider);
            try {
                $access->downloadForCustomer('invoice-1', $this->user());
                self::fail('A refused invoice must not be rendered.');
            } catch (\App\Service\Invoice\InvoiceUnavailable $exception) {
                self::assertSame('Facture introuvable.', $exception->getMessage());
            }
        }
    }

    public function testAdminAllowsUnpaidAndCustomerUsesCaseInsensitiveOwnershipEvenWhenAdminPdfDisabled(): void
    {
        foreach (['admin', 'customer'] as $audience) {
            $invoice = $this->invoice('ALICE@example.test', $audience === 'admin' ? 'awaiting_payment' : 'paid');
            $repository = $this->createMock(\Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface::class);
            $repository->method('find')->willReturn($invoice);
            $provider = $this->createMock(\Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface::class);
            $provider->expects(self::once())->method('provide')->with($invoice)->willThrowException(new \RuntimeException('pdf boundary'));
            $access = new \App\Service\Invoice\InvoiceAccess($repository, $provider, $audience === 'admin');
            try {
                $audience === 'admin' ? $access->downloadForAdmin('invoice-1') : $access->downloadForCustomer('invoice-1', $this->user());
                self::fail('The provider failure must propagate.');
            } catch (\RuntimeException $exception) {
                self::assertSame('pdf boundary', $exception->getMessage());
            }
        }
    }

    public function testAdminDisabledPdfPrecedesLookupAndMissingInvoiceKeeps404Payload(): void
    {
        foreach ([false, true] as $enabled) {
            $repository = $this->createMock(\Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface::class);
            $repository->expects($enabled ? self::once() : self::never())->method('find')->willReturn(null);
            $provider = $this->createMock(\Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface::class);
            $provider->expects(self::never())->method('provide');
            $controller = new \App\Controller\AdminInvoiceApiController(new \App\Service\Invoice\InvoiceAccess($repository, $provider, $enabled));
            $response = $controller->download('unknown');
            self::assertSame(404, $response->getStatusCode());
            self::assertSame(['error' => $enabled ? 'Facture introuvable.' : 'Generation PDF desactivee.'], json_decode($response->getContent(), true));
        }
    }

    public function testAdminListPreservesOrderFilterLimitAndPayload(): void
    {
        $invoice = $this->invoice('alice@example.test', 'paid');
        $invoice->method('id')->willReturn('invoice-1');
        $invoice->method('number')->willReturn('2026-001');
        $invoice->method('issuedAt')->willReturn(new \DateTimeImmutable('2026-01-01T12:00:00Z'));
        $invoice->method('total')->willReturn(1500);
        $invoice->method('currencyCode')->willReturn('EUR');
        $repository = $this->createMock(\Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface::class);
        $repository->expects(self::once())->method('findByOrderNumber')->with('ORDER-1')->willReturn([$invoice]);
        $repository->expects(self::once())->method('findBy')->with([], ['issuedAt' => 'DESC'], 100)->willReturn([$invoice]);
        $controller = new \App\Controller\AdminInvoiceApiController(new \App\Service\Invoice\InvoiceAccess($repository, $this->createMock(\Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface::class)));
        foreach ([[], ['orderNumber' => ' ORDER-1 ']] as $query) {
            self::assertSame(['member' => [[
                'id' => 'invoice-1', 'number' => '2026-001', 'orderNumber' => 'ORDER-1',
                'issuedAt' => '2026-01-01T12:00:00+00:00', 'total' => 1500, 'currencyCode' => 'EUR', 'paymentState' => 'paid',
            ]]], json_decode($controller->index(new \Symfony\Component\HttpFoundation\Request($query))->getContent(), true));
        }
    }

    public function testInvoiceFromAnotherTenantIsUnavailableForBothAudiences(): void
    {
        // Boundary double: the tenant DB returns only its invoices, even with identical emails.
        // JWT and DB switching are covered by JwtTenantIsolationTest/TenantAdapterIsolationTest.
        $context = new \App\Service\Tenant\TenantContext(new \App\Service\Tenant\TenantRegistry('/nonexistent', false), new \App\Service\Tenant\TenantIdentifierResolver(), 'alpha');
        $context->setSlug('alpha');
        $invoice = $this->invoice('alice@example.test', 'paid');
        $repository = $this->createMock(\Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface::class);
        $repository->expects(self::exactly(3))->method('find')->with('alpha-invoice')->willReturnCallback(static fn () => $context->getSlug() === 'alpha' ? $invoice : null);
        $provider = $this->createMock(\Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface::class);
        $provider->expects(self::once())->method('provide')->with($invoice)->willThrowException(new \RuntimeException('alpha storage boundary'));
        $access = new \App\Service\Invoice\InvoiceAccess($repository, $provider);
        try {
            $access->downloadForCustomer('alpha-invoice', $this->user());
        } catch (\RuntimeException $exception) {
            self::assertSame('alpha storage boundary', $exception->getMessage());
        }
        $context->setSlug('beta');
        foreach (['admin', 'customer'] as $audience) {
            try {
                $audience === 'admin' ? $access->downloadForAdmin('alpha-invoice') : $access->downloadForCustomer('alpha-invoice', $this->user());
                self::fail('A foreign tenant invoice must be unavailable.');
            } catch (\App\Service\Invoice\InvoiceUnavailable $exception) {
                self::assertSame('Facture introuvable.', $exception->getMessage());
            }
        }
    }

    private function invoice(string $email, string $state): \Sylius\InvoicingPlugin\Entity\InvoiceInterface
    {
        $customer = new \App\Entity\Customer\Customer();
        $customer->setEmail($email);
        $order = $this->createMock(\Sylius\Component\Core\Model\OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getNumber')->willReturn('ORDER-1');
        $invoice = $this->createMock(\Sylius\InvoicingPlugin\Entity\InvoiceInterface::class);
        $invoice->method('order')->willReturn($order);
        $invoice->method('paymentState')->willReturn($state);
        return $invoice;
    }

    private function user(): \App\Entity\User\ShopUser
    {
        $customer = new \App\Entity\Customer\Customer();
        $customer->setEmail('alice@example.test');
        $user = new \App\Entity\User\ShopUser();
        $user->setCustomer($customer);
        return $user;
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
