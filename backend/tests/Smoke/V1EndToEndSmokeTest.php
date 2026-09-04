<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Entity\Booking;
use App\Entity\Channel\Channel;
use App\Entity\Customer\Customer;
use App\Entity\Payment\PaymentMethod;
use App\Entity\Planning;
use App\Entity\Product\Product;
use App\Entity\StaffMember;
use App\Entity\StripeWebhookEvent;
use App\Entity\User\AdminUser;
use App\Tenant\MinimalSyliusInitializer;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\InvoicingPlugin\Doctrine\ORM\InvoiceRepositoryInterface;
use Sylius\InvoicingPlugin\Entity\InvoiceInterface;
use Sylius\InvoicingPlugin\Provider\InvoiceFileProviderInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * Smoke test V1 de bout en bout : initialise un tenant, connecte un admin, crée une
 * prestation/un collaborateur/un planning, inscrit un client, réserve un créneau,
 * règle par carte (webhook Stripe signé), vérifie l'email transactionnel intercepté
 * et la facture générée, puis déplace et annule la réservation.
 *
 * Aucune fixture métier n'est injectée directement en base : chaque étape métier
 * passe par la même API HTTP que les clients réels (front admin, front boutique,
 * webhook Stripe). Seuls le rattachement du canal à un nom d'hôte et la clé de
 * webhook Stripe — une configuration d'environnement, pas une donnée métier — sont
 * posés directement, comme le ferait un déploiement réel avant toute utilisation.
 *
 * Exécution : voir docs/e2e-smoke-test.md (make test-business, ou vendor/bin/phpunit
 * --configuration phpunit.business.xml --filter V1EndToEndSmokeTest).
 */
final class V1EndToEndSmokeTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private string $runId;

    private string $adminEmail;

    private string $adminPassword;

    private ?string $adminToken = null;

    private string $clientEmail;

    private string $clientPassword;

    private ?string $clientToken = null;

    private ?string $serviceCode = null;

    private int $serviceDurationMinutes = 45;

    private int $servicePriceCents = 6000;

    private ?int $staffMemberId = null;

    private ?string $planningCode = null;

    private ?string $orderTokenValue = null;

    private ?string $orderNumber = null;

    private ?int $paymentId = null;

    private ?string $bookingReference = null;

    private ?string $bookingPublicToken = null;

    private ?int $bookingId = null;

    private string $channelHostname;

    private ?string $originalChannelHostname = null;

    private bool $originalAccountVerificationRequired = true;

    private string $stripeWebhookSecret;

    private bool $originalStripeEnabled = false;

    /** @var array<string, mixed> */
    private array $originalStripeGatewayConfig = [];

    private ?string $invoicePdfPath = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Une seule et même requête PHP tient tout le scénario : sans ceci, le client
        // redémarre le noyau (donc la connexion Doctrine) entre deux appels HTTP, et
        // les lectures directes via l'EntityManager entre deux étapes deviennent
        // invisibles ou obsolètes. Rien n'est mocké : chaque appel traverse toujours
        // la pile HTTP (routage, sécurité, contrôleurs, base) comme une vraie requête.
        $this->client->disableReboot();

        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->runId = bin2hex(random_bytes(4));

        $this->emailTransport()->reset();

        $initializer = $container->get(MinimalSyliusInitializer::class);
        self::assertInstanceOf(MinimalSyliusInitializer::class, $initializer);
        $this->adminEmail = sprintf('admin-smoke-%s@example.test', $this->runId);
        $this->adminPassword = (string) $initializer->initialize(
            'TodaTempo Smoke Test',
            $this->adminEmail,
        );
        self::assertNotSame('', $this->adminPassword, 'Étape « tenant initialisé » : aucun mot de passe admin généré.');

        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        self::assertInstanceOf(Channel::class, $channel, 'Étape « tenant initialisé » : canal FASHION_WEB introuvable après initialisation.');
        $this->originalChannelHostname = $channel->getHostname();
        $this->originalAccountVerificationRequired = $channel->isAccountVerificationRequired();
        $this->channelHostname = sprintf('smoke-%s.todatempo.test', $this->runId);
        $channel->setHostname($this->channelHostname);
        // Un centre fraîchement initialisé exige la vérification d'email avant
        // connexion (valeur par défaut Sylius) ; ce scénario inscrit et connecte le
        // client dans la foulée, comme le fait déjà l'admin via son propre compte.
        $channel->setAccountVerificationRequired(false);

        $stripeMethod = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']);
        self::assertInstanceOf(PaymentMethod::class, $stripeMethod, 'Étape « tenant initialisé » : moyen de paiement stripe_web_elements introuvable.');
        $gatewayConfig = $stripeMethod->getGatewayConfig();
        self::assertNotNull($gatewayConfig);
        $this->originalStripeEnabled = $stripeMethod->isEnabled();
        $this->originalStripeGatewayConfig = $gatewayConfig->getConfig();
        $this->stripeWebhookSecret = 'whsec_smoke_' . $this->runId;
        $stripeMethod->setEnabled(true);
        $gatewayConfig->setConfig(['secret_key' => 'sk_test_smoke', 'webhook_secret_key' => $this->stripeWebhookSecret]);

        $this->entityManager->flush();

        $this->client->setServerParameter('HTTP_HOST', $this->channelHostname);

        $this->clientEmail = sprintf('client-smoke-%s@example.test', $this->runId);
        $this->clientPassword = 'Sm0ke-Test-' . $this->runId;
    }

    public function testFullBookingLifecycleFromTenantSetupToCancellation(): void
    {
        $this->authenticateAdmin();
        $this->createService();
        $this->createStaffMember();
        $this->createPlanning();
        $this->registerAndAuthenticateClient();
        $slot = $this->pickBookableSlot();
        $this->completeCommercialOrder($slot);
        $this->createBooking($slot);
        $this->payWithStripeTestCard();
        $this->assertTransactionalEmailsIntercepted();
        $this->assertInvoiceGenerated();
        $this->rescheduleBooking($slot);
        $this->cancelBooking();
    }

    private function authenticateAdmin(): void
    {
        $response = $this->api('POST', '/api/v2/admin/administrators/token', [
            'email' => $this->adminEmail,
            'password' => $this->adminPassword,
        ]);
        $this->assertStatus(200, $response, 'admin connecté');
        self::assertIsString($response['data']['token'] ?? null, 'Étape « admin connecté » : aucun JWT retourné — ' . $response['raw']);
        $this->adminToken = $response['data']['token'];
    }

    private function createService(): void
    {
        $code = 'service_smoke_' . $this->runId;
        $name = 'Massage signature smoke ' . $this->runId;

        $product = $this->api('POST', '/api/v2/admin/products', [
            'code' => $code,
            'enabled' => true,
            'channels' => ['/api/v2/admin/channels/FASHION_WEB'],
            'translations' => ['en_US' => [
                'name' => $name,
                'slug' => 'massage-signature-smoke-' . $this->runId,
                'shortDescription' => 'Prestation créée par le smoke test V1.',
                'description' => 'Prestation créée par le smoke test V1.',
            ]],
        ], $this->adminToken);
        $this->assertStatus(201, $product, 'prestation créée (produit)');

        $variant = $this->api('POST', '/api/v2/admin/product-variants', [
            'code' => $code . '-variant',
            'product' => '/api/v2/admin/products/' . $code,
            'translations' => ['en_US' => ['name' => $name]],
            'channelPricings' => ['FASHION_WEB' => ['price' => $this->servicePriceCents]],
            'shippingRequired' => false,
            'tracked' => false,
        ], $this->adminToken);
        $this->assertStatus(201, $variant, 'prestation créée (variante et tarif)');

        $attributeCheck = $this->api('GET', '/api/v2/admin/product-attributes/todatempo_duration', [], $this->adminToken);
        if ($attributeCheck['status'] === 404) {
            $attribute = $this->api('POST', '/api/v2/admin/product-attributes', [
                'code' => 'todatempo_duration',
                'type' => 'integer',
                'translatable' => false,
                'translations' => ['en_US' => ['name' => 'Durée de la prestation (min)']],
                'configuration' => (object) [],
            ], $this->adminToken);
            $this->assertStatus(201, $attribute, 'prestation créée (définition de l’attribut durée)');
        }

        $withDuration = $this->api('PUT', '/api/v2/admin/products/' . $code, [
            'attributes' => [
                ['attribute' => '/api/v2/admin/product-attributes/todatempo_duration', 'value' => $this->serviceDurationMinutes],
            ],
        ], $this->adminToken);
        $this->assertStatus(200, $withDuration, 'prestation créée (durée renseignée)');

        $this->serviceCode = $code;
    }

    private function createStaffMember(): void
    {
        $response = $this->api('POST', '/api/v2/admin/staff-members', [
            'firstName' => 'Alex',
            'lastName' => 'Smoke' . $this->runId,
            'color' => '#2a6f6a',
            'active' => true,
            'bookable' => true,
            'serviceCodes' => [$this->serviceCode],
            'workingHours' => $this->fullWeekWorkingHours(),
        ], $this->adminToken);
        $this->assertStatus(201, $response, 'collaborateur créé');
        self::assertIsInt($response['data']['id'] ?? null, 'Étape « collaborateur créé » : identifiant manquant — ' . $response['raw']);
        $this->staffMemberId = $response['data']['id'];
    }

    private function createPlanning(): void
    {
        $response = $this->api('POST', '/api/v2/admin/plannings', [
            'name' => 'Planning smoke ' . $this->runId,
            'timezone' => 'Europe/Paris',
            'capacity' => 5,
            'serviceCodes' => [$this->serviceCode],
            'active' => true,
            'days' => $this->fullWeekPlanningDays(),
        ], $this->adminToken);
        $this->assertStatus(201, $response, 'planning créé');
        self::assertIsString($response['data']['code'] ?? null, 'Étape « planning créé » : code manquant — ' . $response['raw']);
        $this->planningCode = $response['data']['code'];
    }

    private function registerAndAuthenticateClient(): void
    {
        $registration = $this->api('POST', '/api/v2/shop/customers', [
            'firstName' => 'Camille',
            'lastName' => 'Cliente',
            'email' => $this->clientEmail,
            'password' => $this->clientPassword,
            'subscribedToNewsletter' => false,
        ]);
        self::assertContains($registration['status'], [200, 201], 'Étape « client inscrit » : inscription refusée — ' . $registration['raw']);

        $login = $this->api('POST', '/api/v2/shop/customers/token', [
            'email' => $this->clientEmail,
            'password' => $this->clientPassword,
        ]);
        $this->assertStatus(200, $login, 'client inscrit (connexion)');
        self::assertIsString($login['data']['token'] ?? null, 'Étape « client inscrit » : aucun JWT retourné après connexion — ' . $login['raw']);
        $this->clientToken = $login['data']['token'];
    }

    /** @return array{start: string, end: string, planningCode: string, resourceCode: ?string} */
    private function pickBookableSlot(): array
    {
        $from = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $to = $from->modify('+13 days');
        $response = $this->api('GET', sprintf(
            '/api/v2/shop/availability?serviceCode=%s&from=%s&to=%s',
            urlencode((string) $this->serviceCode),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        ), [], $this->clientToken);
        $this->assertStatus(200, $response, 'créneau réservé (disponibilités)');

        $slots = \is_array($response['data']['member'] ?? null) ? $response['data']['member'] : [];
        $paris = new \DateTimeZone('Europe/Paris');
        foreach ($slots as $slot) {
            if (!\is_array($slot) || ($slot['staffMemberId'] ?? null) !== $this->staffMemberId) {
                continue;
            }
            $start = $this->stringField($slot, 'start', 'créneau réservé');
            $localHour = (int) (new \DateTimeImmutable($start))->setTimezone($paris)->format('H');
            // Une fenêtre médiane laisse de la marge pour le déplacement (+90 min)
            // testé plus loin, quel que soit le jour retenu par l'algorithme.
            if ($localHour >= 11 && $localHour <= 15) {
                $resourceCode = $slot['resourceCode'] ?? null;
                self::assertTrue($resourceCode === null || \is_string($resourceCode), 'Étape « créneau réservé » : code de ressource invalide.');

                return [
                    'start' => $start,
                    'end' => $this->stringField($slot, 'end', 'créneau réservé'),
                    'planningCode' => $this->stringField($slot, 'planningCode', 'créneau réservé'),
                    'resourceCode' => $resourceCode,
                ];
            }
        }

        self::fail(sprintf(
            'Étape « créneau réservé » : aucun créneau exploitable parmi %d proposés pour %s entre %s et %s — %s',
            \count($slots),
            (string) $this->serviceCode,
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $response['raw'],
        ));
    }

    /** @param array{start: string, end: string, planningCode: string, resourceCode: ?string} $slot */
    private function completeCommercialOrder(array $slot): void
    {
        $cart = $this->api('POST', '/api/v2/shop/orders', [], $this->clientToken);
        $this->assertStatus(201, $cart, 'créneau réservé (panier)');
        $tokenValue = $this->stringField($cart['data'], 'tokenValue', 'créneau réservé');

        $item = $this->api('POST', sprintf('/api/v2/shop/orders/%s/items', $tokenValue), [
            'productVariant' => '/api/v2/shop/product-variants/' . $this->serviceCode . '-variant',
            'quantity' => 1,
        ], $this->clientToken);
        $this->assertStatus(200, $item, 'créneau réservé (ajout de la prestation au panier)');

        $addressed = $this->api('PUT', '/api/v2/shop/orders/' . $tokenValue, [
            'email' => $this->clientEmail,
            'billingAddress' => [
                'firstName' => 'Camille',
                'lastName' => 'Cliente',
                'countryCode' => 'FR',
                'street' => '1 rue du Smoke Test',
                'city' => 'Paris',
                'postcode' => '75001',
            ],
        ], $this->clientToken);
        $this->assertStatus(200, $addressed, 'créneau réservé (adresse de facturation)');

        $cartState = $this->api('GET', '/api/v2/shop/orders/' . $tokenValue, [], $this->clientToken);
        $this->assertStatus(200, $cartState, 'créneau réservé (relecture du panier)');
        $payments = \is_array($cartState['data']['payments'] ?? null) ? $cartState['data']['payments'] : [];
        $firstPayment = $payments[0] ?? null;
        self::assertIsArray($firstPayment, 'Étape « créneau réservé » : aucun paiement généré pour le panier — ' . $cartState['raw']);
        $paymentId = $this->intField($firstPayment, 'id', 'créneau réservé');

        $terms = $this->api('POST', sprintf('/api/v2/shop/orders/%s/payment-terms', $tokenValue), [], $this->clientToken);
        $this->assertStatus(200, $terms, 'créneau réservé (règle de paiement de la prestation)');

        $paymentMethod = $this->api('PATCH', sprintf('/api/v2/shop/orders/%s/payments/%d', $tokenValue, $paymentId), [
            'paymentMethod' => '/api/v2/shop/payment-methods/stripe_web_elements',
        ], $this->clientToken);
        $this->assertStatus(200, $paymentMethod, 'paiement test (choix de la carte bancaire)');

        $completed = $this->api('PATCH', sprintf('/api/v2/shop/orders/%s/complete', $tokenValue), [
            'notes' => 'Smoke test TodaTempo V1 — ' . $this->runId,
        ], $this->clientToken);
        $this->assertStatus(200, $completed, 'créneau réservé (finalisation de la commande)');

        $this->orderTokenValue = $tokenValue;
        $this->orderNumber = $this->stringField($completed['data'], 'number', 'créneau réservé');
        $this->paymentId = $paymentId;
    }

    /** @param array{start: string, end: string, planningCode: string, resourceCode: ?string} $slot */
    private function createBooking(array $slot): void
    {
        $response = $this->api('POST', '/api/v2/shop/bookings', [
            'source' => 'direct',
            'serviceCode' => $this->serviceCode,
            'planningCode' => $slot['planningCode'],
            'resourceCode' => $slot['resourceCode'],
            'staffMemberId' => $this->staffMemberId,
            'start' => $slot['start'],
            'end' => $slot['end'],
            'customer' => [
                'firstName' => 'Camille',
                'lastName' => 'Cliente',
                'email' => $this->clientEmail,
                'phone' => '+33600000000',
                'smsReminderConsent' => false,
                'notes' => '',
            ],
            'options' => [],
            'orderNumber' => $this->orderNumber,
            'orderToken' => $this->orderTokenValue,
            'voucherCode' => null,
        ], $this->clientToken);
        $this->assertStatus(201, $response, 'créneau réservé (réservation)');
        self::assertSame(Booking::STATUS_AWAITING_PAYMENT, $response['data']['status'] ?? null, 'Étape « créneau réservé » : statut inattendu avant paiement — ' . $response['raw']);

        $this->bookingPublicToken = $this->stringField($response['data'], 'id', 'créneau réservé');
        $this->bookingReference = $this->stringField($response['data'], 'reference', 'créneau réservé');

        $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['reference' => $this->bookingReference]);
        self::assertInstanceOf(Booking::class, $booking, 'Étape « créneau réservé » : réservation introuvable en base après création.');
        $this->bookingId = $booking->getId();
    }

    private function payWithStripeTestCard(): void
    {
        $eventId = 'evt_smoke_' . $this->runId;
        $payload = json_encode([
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_smoke_' . $this->runId,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_smoke_' . $this->runId,
                'metadata' => [
                    'order_token' => $this->orderTokenValue,
                    'payment_id' => (string) $this->paymentId,
                    'booking_token' => $this->bookingPublicToken,
                ],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $this->stripeWebhookSecret);
        $this->client->request(
            'POST',
            '/api/v2/shop/payments/stripe/webhook/demo',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $timestamp, $signature),
            ],
            content: $payload,
        );
        $webhookResponse = $this->client->getResponse();
        $body = (string) $webhookResponse->getContent();
        self::assertSame(200, $webhookResponse->getStatusCode(), 'Étape « paiement test » : webhook Stripe refusé — ' . $body);
        self::assertStringNotContainsString('"replayed":true', $body, 'Étape « paiement test » : le webhook a été traité comme un rejeu — ' . $body);

        $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['reference' => $this->bookingReference]);
        self::assertInstanceOf(Booking::class, $booking, 'Étape « paiement test » : réservation introuvable après le webhook.');
        self::assertSame(Booking::STATUS_CONFIRMED, $booking->getStatus(), 'Étape « paiement test » : la réservation n’a pas été confirmée par le paiement.');
        self::assertSame('paid', $booking->getPaymentState(), 'Étape « paiement test » : le paiement n’est pas marqué payé.');
    }

    private function assertTransactionalEmailsIntercepted(): void
    {
        $recipients = [];
        foreach ($this->emailTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof SendEmailMessage) {
                continue;
            }
            $email = $message->getMessage();
            if ($email instanceof Email) {
                foreach ($email->getTo() as $address) {
                    $recipients[] = strtolower($address->getAddress());
                }
            }
        }

        self::assertGreaterThanOrEqual(
            2,
            \count(array_filter($recipients, fn (string $address): bool => $address === strtolower($this->clientEmail))),
            'Étape « email intercepté » : moins de deux emails transactionnels (confirmation + paiement) interceptés pour le client — reçus : ' . implode(', ', $recipients),
        );
    }

    private function assertInvoiceGenerated(): void
    {
        $index = $this->api('GET', '/api/v2/admin/invoices?orderNumber=' . urlencode((string) $this->orderNumber), [], $this->adminToken);
        $this->assertStatus(200, $index, 'facture générée (recherche)');
        $invoices = \is_array($index['data']['member'] ?? null) ? $index['data']['member'] : [];
        self::assertNotEmpty($invoices, 'Étape « facture générée » : aucune facture trouvée pour la commande ' . $this->orderNumber . ' — ' . $index['raw']);
        $firstInvoice = $invoices[0];
        self::assertIsArray($firstInvoice, 'Étape « facture générée » : réponse inattendue — ' . $index['raw']);
        $invoiceId = $this->stringField($firstInvoice, 'id', 'facture générée');

        $this->client->request('GET', '/api/v2/admin/invoices/' . $invoiceId . '/download', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->adminToken,
        ]);
        $download = $this->client->getResponse();
        self::assertSame(
            200,
            $download->getStatusCode(),
            'Étape « facture générée » : téléchargement du PDF refusé (le binaire wkhtmltopdf est-il installé ? voir docs/invoices.md) — HTTP ' . $download->getStatusCode(),
        );
        $content = (string) $download->getContent();
        self::assertStringStartsWith('%PDF', $content, 'Étape « facture générée » : le contenu téléchargé n’est pas un PDF.');

        $invoice = $this->invoiceRepository()->find($invoiceId);
        if ($invoice instanceof InvoiceInterface) {
            $this->invoicePdfPath = $this->invoiceFileProvider()->provide($invoice)->fullPath();
        }
    }

    /** @param array{start: string, end: string, planningCode: string, resourceCode: ?string} $slot */
    private function rescheduleBooking(array $slot): void
    {
        $newStart = (new \DateTimeImmutable($slot['start']))->modify('+90 minutes');
        $newEnd = $newStart->modify('+' . $this->serviceDurationMinutes . ' minutes');

        $response = $this->api('POST', sprintf('/api/v2/admin/bookings/%d/reschedule', $this->bookingId), [
            'start' => $newStart->format(\DateTimeInterface::ATOM),
            'end' => $newEnd->format(\DateTimeInterface::ATOM),
            'staffMemberId' => $this->staffMemberId,
            'planningCode' => $this->planningCode,
        ], $this->adminToken);
        $this->assertStatus(200, $response, 'déplacement');
        self::assertSame(Booking::STATUS_CONFIRMED, $response['data']['status'] ?? null, 'Étape « déplacement » : statut inattendu après déplacement — ' . $response['raw']);
        self::assertSame($newStart->format(\DateTimeInterface::ATOM), $response['data']['slotStart'] ?? null, 'Étape « déplacement » : le nouveau créneau n’a pas été appliqué — ' . $response['raw']);
    }

    private function cancelBooking(): void
    {
        $response = $this->api('POST', sprintf('/api/v2/admin/bookings/%d/cancel', $this->bookingId), [], $this->adminToken);
        $this->assertStatus(200, $response, 'annulation');
        self::assertSame(Booking::STATUS_CANCELLED, $response['data']['status'] ?? null, 'Étape « annulation » : statut inattendu après annulation — ' . $response['raw']);
    }

    /** @return array<string, array{enabled: bool, start: string, end: string}> */
    private function fullWeekWorkingHours(): array
    {
        $hours = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $hours[$day] = ['enabled' => true, 'start' => '09:00', 'end' => '18:00'];
        }

        return $hours;
    }

    /** @return array<string, list<array{start: string, end: string}>> */
    private function fullWeekPlanningDays(): array
    {
        $days = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $days[$day] = [['start' => '09:00', 'end' => '18:00']];
        }

        return $days;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, data: array<mixed>, raw: string}
     */
    private function api(string $method, string $uri, array $body = [], ?string $bearer = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'];
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
        }
        $content = \in_array($method, ['GET', 'DELETE'], true) && $body === [] ? null : ($body === [] ? '{}' : json_encode($body, \JSON_THROW_ON_ERROR));

        $this->client->request($method, $uri, server: $server, content: $content);
        $response = $this->client->getResponse();
        $raw = (string) $response->getContent();
        $data = json_decode($raw, true);

        return ['status' => $response->getStatusCode(), 'data' => \is_array($data) ? $data : [], 'raw' => $raw];
    }

    /** @param array{status: int, data: array<mixed>, raw: string} $response */
    private function assertStatus(int $expected, array $response, string $step): void
    {
        self::assertSame($expected, $response['status'], sprintf('Étape « %s » : attendu HTTP %d, obtenu %d — %s', $step, $expected, $response['status'], $response['raw']));
    }

    protected function tearDown(): void
    {
        $failures = [];

        if ($this->bookingReference !== null) {
            $booking = $this->entityManager->getRepository(Booking::class)->findOneBy(['reference' => $this->bookingReference]);
            if ($booking !== null) {
                $this->removeSafely($booking, 'réservation', $failures);
            }
        }

        if ($this->orderNumber !== null) {
            try {
                foreach ($this->invoiceRepository()->findByOrderNumber($this->orderNumber) as $invoice) {
                    $this->entityManager->remove($invoice);
                }
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                $failures[] = 'facture : ' . $exception->getMessage();
            }
        }
        if ($this->invoicePdfPath !== null) {
            @unlink($this->invoicePdfPath);
        }

        $event = $this->entityManager->getRepository(StripeWebhookEvent::class)->findOneBy(['eventId' => 'evt_smoke_' . $this->runId]);
        if ($event !== null) {
            $this->removeSafely($event, 'événement Stripe', $failures);
        }

        $customer = $this->entityManager->getRepository(Customer::class)->findOneBy(['email' => $this->clientEmail]);
        if ($customer !== null) {
            // Cascade Sylius : supprime aussi le compte client, ses commandes et adresses.
            $this->removeSafely($customer, 'client', $failures);
        }

        if ($this->serviceCode !== null) {
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['code' => $this->serviceCode]);
            if ($product !== null) {
                // Cascade Sylius : supprime aussi la variante, son tarif et ses attributs.
                $this->removeSafely($product, 'prestation', $failures);
            }
        }

        if ($this->planningCode !== null) {
            $planning = $this->entityManager->getRepository(Planning::class)->findOneBy(['code' => $this->planningCode]);
            if ($planning !== null) {
                $this->removeSafely($planning, 'planning', $failures);
            }
        }

        if ($this->staffMemberId !== null) {
            $staff = $this->entityManager->find(StaffMember::class, $this->staffMemberId);
            if ($staff !== null) {
                $this->removeSafely($staff, 'collaborateur', $failures);
            }
        }

        $admin = $this->entityManager->getRepository(AdminUser::class)->findOneBy(['email' => $this->adminEmail]);
        if ($admin !== null) {
            $this->removeSafely($admin, 'administrateur', $failures);
        }

        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        if ($channel !== null) {
            $channel->setHostname($this->originalChannelHostname);
            $channel->setAccountVerificationRequired($this->originalAccountVerificationRequired);
        }
        $stripeMethod = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']);
        if ($stripeMethod !== null) {
            $stripeMethod->setEnabled($this->originalStripeEnabled);
            $stripeMethod->getGatewayConfig()?->setConfig($this->originalStripeGatewayConfig);
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $failures[] = 'restauration du tenant : ' . $exception->getMessage();
        }

        parent::tearDown();

        self::assertSame([], $failures, 'Nettoyage des données du smoke test incomplet : ' . implode(' | ', $failures));
    }

    /** @param list<string> $failures */
    private function removeSafely(object $entity, string $label, array &$failures): void
    {
        try {
            $this->entityManager->remove($entity);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $failures[] = $label . ' : ' . $exception->getMessage();
        }
    }

    /** @param array<mixed> $data */
    private function stringField(array $data, string $key, string $step): string
    {
        $value = $data[$key] ?? null;
        self::assertIsString($value, sprintf('Étape « %s » : champ « %s » manquant ou invalide.', $step, $key));

        return $value;
    }

    /** @param array<mixed> $data */
    private function intField(array $data, string $key, string $step): int
    {
        $value = $data[$key] ?? null;
        self::assertIsInt($value, sprintf('Étape « %s » : champ « %s » manquant ou invalide.', $step, $key));

        return $value;
    }

    private function emailTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function invoiceRepository(): InvoiceRepositoryInterface
    {
        $repository = self::getContainer()->get('sylius_invoicing.repository.invoice');
        self::assertInstanceOf(InvoiceRepositoryInterface::class, $repository);

        return $repository;
    }

    private function invoiceFileProvider(): InvoiceFileProviderInterface
    {
        $provider = self::getContainer()->get('sylius_invoicing.provider.invoice_file');
        self::assertInstanceOf(InvoiceFileProviderInterface::class, $provider);

        return $provider;
    }
}
