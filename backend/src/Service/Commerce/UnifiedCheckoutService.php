<?php

declare(strict_types=1);
namespace App\Service\Commerce;

use App\Entity\Addressing\Address;
use App\Entity\Booking;
use App\Entity\Channel\Channel;
use App\Entity\Customer\Customer;
use App\Entity\Order\{Order, OrderItem, OrderItemUnit, Adjustment};
use App\Entity\Payment\{Payment, PaymentMethod};
use App\Entity\Product\{Product, ProductVariant};
use App\Service\Booking\{BookingCreationService, BookingRules, BookingView};
use App\Service\GiftCard\{GiftCardCheckoutService, GiftCardPaymentService};
use App\Service\Payment\OrderPaymentTermsService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\Registry;

/** One tenant transaction owns prices, stock, booking and gift credit. No external payment call here. */
final class UnifiedCheckoutService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'sylius.order_processing.order_processor')] private readonly OrderProcessorInterface $processor,
        private readonly PhysicalCheckoutService $physical,
        private readonly OrderPaymentTermsService $terms,
        private readonly BookingCreationService $bookings,
        private readonly BookingRules $rules,
        private readonly GiftCardCheckoutService $gifts,
        private readonly GiftCardPaymentService $credit,
        private readonly Registry $workflows,
        private readonly BookingView $view,
        private readonly \App\Service\Email\BookingEmailDispatcher $emails,
    ) {}

    public function checkout(array $data): array
    {
        $key = $data['key'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32,64}$/D', $key)) throw new \InvalidArgumentException('Référence de panier invalide.');
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
            if (!$channel instanceof Channel || !$channel->isEnabled() || $channel->getBaseCurrency()?->getCode() !== 'EUR') throw new \DomainException('Boutique indisponible.');
            // Coarse tenant-local lock: also serialises first requests with the same key.
            $this->em->refresh($channel, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->em->getRepository(Order::class)->createQueryBuilder('o')->where('o.checkoutKey = :key')->setParameter('key', $key)->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
            if ($existing instanceof Order) {
                $this->em->refresh($existing, LockMode::PESSIMISTIC_WRITE);
                if (($existing->getCheckoutContext()['fingerprint'] ?? '') !== $fingerprint) throw new \DomainException('Ce panier a déjà été envoyé avec un autre contenu. Consultez la commande avant de recommencer.');
                $result = $this->result($existing); $connection->commit(); return $result;
            }
            $customerData = $data['customer'] ?? null;
            if (!is_array($customerData)) throw new \InvalidArgumentException('Renseignez vos coordonnées.');
            foreach (['firstName', 'lastName', 'email'] as $field) {
                if (!is_string($customerData[$field] ?? null) || trim($customerData[$field]) === '' || mb_strlen($customerData[$field]) > ($field === 'email' ? 180 : 100)) throw new \InvalidArgumentException('Renseignez vos coordonnées.');
            }
            $email = mb_strtolower(trim($customerData['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('L’adresse e-mail est invalide.');
            $order = new Order(); $order->configureCheckout($key, ['fingerprint' => $fingerprint]);
            $order->setChannel($channel); $order->setCurrencyCode('EUR'); $order->setLocaleCode($channel->getDefaultLocale()?->getCode() ?? 'fr_FR');
            $order->setTokenValue(bin2hex(random_bytes(32)));
            $customer = $this->em->getRepository(Customer::class)->findOneBy(['emailCanonical' => $email]);
            if (!$customer instanceof Customer) { $customer = new Customer(); $customer->setEmail($email); $customer->setEmailCanonical($email); $this->em->persist($customer); }
            $order->setCustomer($customer);
            $lines = $data['items'] ?? [];
            $gifts = $data['gifts'] ?? [];
            if (!is_array($lines) || !array_is_list($lines) || count($lines) > 50 || !is_array($gifts) || !array_is_list($gifts) || count($gifts) > 10 || ($lines === [] && $gifts === [])) throw new \InvalidArgumentException('Le panier est vide ou trop volumineux.');
            $service = null; $hasPhysical = false; $options = []; $seen = [];
            // Variant lock order is deterministic for concurrent inventory operations.
            usort($lines, static fn ($a, $b) => strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? '')));
            foreach ($lines as $line) {
                if (!is_array($line) || !is_string($line['code'] ?? null) || !is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 99 || isset($seen[$line['code']])) throw new \InvalidArgumentException('Article ou quantité invalide.');
                $seen[$line['code']] = true;
                $product = $this->em->getRepository(Product::class)->findOneBy(['code' => $line['code']]);
                if (!$product instanceof Product || !$product->isEnabled() || !$product->hasChannel($channel)) throw new \DomainException('Un article n’est plus disponible dans cette boutique.');
                $variant = $this->em->getRepository(ProductVariant::class)->findOneBy(['code' => $line['code'].'-variant', 'product' => $product]);
                if (!$variant instanceof ProductVariant || !$variant->isEnabled()) throw new \DomainException('Un article n’est plus disponible.');
                $this->em->refresh($variant, LockMode::PESSIMISTIC_WRITE);
                $price = $variant->getChannelPricingForChannel($channel)?->getPrice();
                if (!is_int($price) || $price < 0) throw new \DomainException('Le prix d’un article est indisponible.');
                if ($product->isPhysical()) {
                    $hasPhysical = true;
                    if (!$variant->isTracked() || $variant->getOnHand() - $variant->getOnHold() < $line['quantity']) throw new \DomainException('Un produit n’est plus disponible en quantité suffisante.');
                } elseif (str_starts_with($line['code'], 'opt_') || str_starts_with($line['code'], 'option_') || $product->getTodatempoType() === Product::TYPE_OPTION) {
                    if ($line['quantity'] !== 1) throw new \DomainException('Une option ne peut être ajoutée qu’une fois.');
                    $options[] = ['name' => (string) $product->getName(), 'price' => $price / 100];
                } else {
                    if ($service !== null || $line['quantity'] !== 1) throw new \DomainException('Une seule réservation de prestation est possible par commande.');
                    $service = $product;
                }
                $item = new OrderItem(); $item->setVariant($variant); $item->setUnitPrice($price);
                for ($i = 0; $i < $line['quantity']; ++$i) new OrderItemUnit($item);
                $order->addItem($item);
            }
            if ($options !== [] && $service === null) throw new \DomainException('Les options accompagnent une prestation.');
            $address = $this->address($customerData, $hasPhysical);
            $order->setBillingAddress($address);
            if (($data['mode'] ?? '') === 'delivery') $order->setShippingAddress(clone $address);
            $purchases = [];
            foreach ($gifts as $gift) {
                if (!is_array($gift) || !is_int($gift['amount'] ?? null)) throw new \InvalidArgumentException('Montant cadeau invalide.');
                $purchases[] = $this->gifts->mixedPurchase($gift['amount'], array_replace($gift, ['buyerName' => trim($customerData['firstName'].' '.$customerData['lastName']), 'buyerEmail' => $email]));
                $adjustment = new Adjustment(); $adjustment->setType('todatempo_gift_card_purchase'); $adjustment->setLabel('Carte cadeau'); $adjustment->setAmount($gift['amount']); $order->addAdjustment($adjustment); $adjustment->lock();
            }
            $order->updateCheckoutContext($order->getCheckoutContext() + ['gifts' => $purchases, 'serviceCode' => $service?->getCode()]);
            $this->em->persist($order); $this->em->flush();
            $this->processor->process($order);
            if ($hasPhysical) $this->physical->configure($order->getTokenValue(), (string) ($data['mode'] ?? ''));
            foreach ($order->getShipments() as $shipment) {
                $shipping = $this->em->getRepository(\App\Entity\Shipping\ShippingMethod::class)->findOneBy(['code' => 'standard', 'enabled' => true]);
                if ($shipping === null || !$shipping->hasChannel($channel)) throw new \DomainException('La remise des produits n’est pas disponible.');
                $shipment->setMethod($shipping);
            }
            $order->setCheckoutState('shipping_skipped');
            if ($service !== null) $this->terms->apply($order->getTokenValue());
            $payment = $order->getLastPayment();
            if ($order->getTotal() > 0) {
                if (!$payment instanceof Payment) { $payment = new Payment(); $payment->setCurrencyCode('EUR'); $payment->setState('cart'); $order->addPayment($payment); $this->em->persist($payment); }
                $payment->setAmount($order->getTotal());
                $methodCode = $data['paymentMethod'] ?? '';
                if (!in_array($methodCode, ['stripe_web_elements', 'bank_transfer', 'gift_card'], true)) throw new \InvalidArgumentException('Choisissez un moyen de paiement.');
                $code = $data['giftCardCode'] ?? '';
                if (!is_string($code)) throw new \InvalidArgumentException('Code cadeau invalide.');
                $this->em->flush();
                if ($code !== '') $this->credit->prepare($order->getTokenValue(), $code);
                else {
                    $method = $this->em->getRepository(PaymentMethod::class)->findOneBy(['code' => $methodCode]);
                    if (!$method instanceof PaymentMethod || $methodCode === 'gift_card' || !$method->isEnabled() || !$method->hasChannel($channel) || ($methodCode === 'stripe_web_elements' && empty($method->getGatewayConfig()?->getConfig()['secret_key']))) throw new \DomainException('Ce moyen de paiement n’est pas disponible.');
                    $payment->setMethod($method);
                    $this->workflows->get($order, 'sylius_order_checkout')->apply($order, 'select_payment');
                }
            } else $order->setCheckoutState('payment_skipped');
            $this->workflows->get($order, 'sylius_order_checkout')->apply($order, 'complete');
            $this->em->flush();
            $createdBooking = null;
            if ($service !== null) {
                $slot = $data['slot'] ?? [];
                if (!is_array($slot) || !is_string($slot['start'] ?? null) || !is_string($slot['end'] ?? null)) throw new \InvalidArgumentException('Choisissez un créneau pour la prestation.');
                try { $start = new \DateTimeImmutable($slot['start']); $end = new \DateTimeImmutable($slot['end']); } catch (\Exception) { throw new \InvalidArgumentException('Créneau invalide.'); }
                if ($start <= new \DateTimeImmutable() || $end <= $start) throw new \DomainException('Ce créneau n’est plus disponible.');
                $this->rules->assertBookableAt($start);
                $createdBooking = $this->bookings->createFromOrder(array_replace($slot, ['orderToken' => $order->getTokenValue(), 'customer' => $customerData, 'options' => $options, 'source' => 'direct']), $start, $end, $service->getCode(), $customerData['firstName'], $customerData['lastName'], $email);
            }
            if (($data['giftCardCode'] ?? '') !== '' && $order->getTotal() > 0) $this->credit->settle($order->getTokenValue());
            if ($createdBooking !== null) $this->emails->confirmation($createdBooking);
            $summary = [];
            foreach ($order->getItems() as $item) $summary[] = ['name' => $item->getProductName(), 'quantity' => $item->getQuantity(), 'total' => $item->getTotal()];
            foreach ($purchases as $purchase) $summary[] = ['name' => 'Carte cadeau pour '.$purchase['recipientName'], 'quantity' => 1, 'total' => $purchase['amount']];
            $order->updateCheckoutContext(array_replace($order->getCheckoutContext(), ['summary' => $summary]));
            foreach ($order->getPayments() as $part) if ($part->getState() === 'new') $part->setDetails(array_replace($part->getDetails(), ['checkout_expires' => time() + 3600]));
            $this->em->flush(); $result = $this->result($order); $connection->commit(); return $result;
        } catch (\Throwable $e) { if ($connection->isTransactionActive()) $connection->rollBack(); $this->em->clear(); throw $e; }
    }

    private function address(array $data, bool $required): Address
    {
        $address = new Address(); $address->setFirstName($data['firstName']); $address->setLastName($data['lastName']);
        foreach (['street' => 255, 'postcode' => 20, 'city' => 255] as $field => $max) {
            $value = $data[$field] ?? '';
            if (!is_string($value) || mb_strlen($value) > $max || ($required && trim($value) === '')) throw new \InvalidArgumentException('Renseignez votre adresse de facturation et de livraison.');
            $address->{'set'.ucfirst($field)}($value ?: 'Non renseigné');
        }
        $country = $data['countryCode'] ?? 'FR';
        if (!is_string($country) || !preg_match('/^[A-Z]{2}$/D', $country)) throw new \InvalidArgumentException('Pays invalide.');
        $address->setCountryCode($country);
        return $address;
    }
    private function result(Order $order): array
    {
        $payment = null;
        foreach ($order->getPayments() as $part) if ($payment === null || $part->getMethod()?->getCode() === 'stripe_web_elements') $payment = $part;
        $booking = $this->em->getRepository(Booking::class)->findOneBy(['orderNumber' => $order->getNumber()]);
        return ['booking' => $booking ? $this->view->publicBooking($booking) : null, 'order' => [
            'id' => $order->getTokenValue(), 'orderToken' => $order->getTokenValue(), 'number' => $order->getNumber(), 'total' => $order->getTotal() / 100,
            'currency' => $order->getCurrencyCode(), 'status' => $order->getPaymentState(), 'paymentMethod' => $payment?->getMethod()?->getCode() ?? 'none', 'paymentId' => $payment?->getId(),
            'paymentInstructions' => ($translation = $payment?->getMethod()?->getTranslations()->first()) ? $translation->getInstructions() : '', 'giftSettled' => true, 'paymentBreakdown' => GiftCardPaymentService::breakdown($order),
            'paymentTerms' => $order->getCheckoutContext()['paymentTerms'] ?? null, 'items' => $order->getCheckoutContext()['summary'] ?? [],
        ]];
    }
}
