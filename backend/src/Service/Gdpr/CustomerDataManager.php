<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use App\Entity\GdprAuditLog;
use App\Service\Tenant\TenantContext;
use App\Repository\CustomerDataRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Façade RGPD : export, effacement et rétention dans le tenant courant.
 * Garde les politiques, la transaction et l'audit ; délègue le SQL au repository.
 * Les snapshots de facturation ne sont jamais anonymisés ni supprimés ici.
 */
final readonly class CustomerDataManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private RetentionPolicy $policy,
        private CustomerDataRepository $data,
    ) {}

    /** @return array<string, mixed> */
    public function export(string $email, string $actor): array
    {
        $email = $this->email($email);
        $customers = $this->data->rows('sylius_customer', 'email', $email);
        $orders = []; $addresses = []; $invoices = [];
        foreach ($customers as $customer) {
            if (!isset($customer['id'])) continue;
            $customerOrders = $this->data->rowsBy('sylius_order', 'customer_id', (int) $customer['id']);
            $orders = array_merge($orders, $customerOrders);
            foreach ($customerOrders as $order) {
                foreach (['billing_address_id', 'shipping_address_id'] as $key) {
                    if (isset($order[$key])) $addresses = array_merge($addresses, $this->data->rowsBy('sylius_address', 'id', (int) $order[$key]));
                }
                if (isset($order['id'])) $invoices = array_merge($invoices, $this->data->rowsBy('sylius_invoicing_invoice', 'order_id', (int) $order['id']));
                if (isset($order['number'])) $invoices = array_merge($invoices, $this->data->rowsBy('sylius_invoicing_invoice', 'order_number', (string) $order['number']));
            }
        }
        $data = [
            'formatVersion' => 1,
            'tenant' => $this->tenantContext->getSlug(),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'subject' => $email,
            'profile' => $this->data->rows('todatempo_client_profile', 'booking_email', $email),
            'bookings' => $this->data->rows('momeo_booking', 'customer_email', $email),
            'waitlistRequests' => $this->data->rows('todatempo_waitlist_request', 'customer_email', $email),
            'giftVouchersPurchased' => $this->data->rows('skybook_gift_voucher', 'purchaser_email', $email),
            'giftVouchersReceived' => $this->data->rows('skybook_gift_voucher', 'beneficiary_email', $email),
            'customerAccount' => $customers,
            'orders' => $orders,
            'orderAddresses' => $addresses,
            'invoices' => $invoices,
        ];
        $this->audit('export', $email, $actor, ['datasets' => array_keys($data)]);

        return $data;
    }

    /** @return array<string, int> */
    public function erase(string $email, string $actor, string $reason = 'data_subject_request'): array
    {
        $email = $this->email($email);
        $connection = $this->entityManager->getConnection();
        $token = 'deleted+'.substr(hash('sha256', $this->tenantContext->getSlug().'|'.$email), 0, 24).'@invalid.local';
        $counts = [];

        $connection->transactional(function () use ($email, $token, $actor, $reason, &$counts): void {
            $customerIds = $this->data->ids('sylius_customer', 'email', $email);
            $addressIds = [];
            foreach ($customerIds as $customerId) {
                $addressIds = array_merge($addressIds, $this->data->orderAddressIds($customerId));
                $orderCount = $this->data->clearOrderNotes($customerId);
                if ($orderCount !== null) $counts['orders'] = ($counts['orders'] ?? 0) + $orderCount;
            }
            foreach (array_unique($addressIds) as $addressId) {
                $counts['orderAddresses'] = ($counts['orderAddresses'] ?? 0) + $this->data->anonymizeById('sylius_address', $addressId, ['first_name' => 'Supprime', 'last_name' => 'RGPD', 'phone_number' => null, 'company' => null, 'street' => '-', 'city' => '-', 'postcode' => '-', 'province_name' => null]);
            }
            $counts['profiles'] = $this->data->delete('todatempo_client_profile', 'booking_email', $email);
            $counts['bookings'] = $this->data->anonymize('momeo_booking', 'customer_email', $email, [
                'customer_first_name' => 'Supprime', 'customer_last_name' => 'RGPD', 'customer_email' => $token,
                'customer_phone' => null, 'customer_notes' => null, 'sms_reminder_consent' => false,
                'public_token' => fn (): string => bin2hex(random_bytes(16)), 'change_history' => '[]',
            ]);
            $counts['waitlistRequests'] = $this->data->delete('todatempo_waitlist_request', 'customer_email', $email);
            $counts['vouchersPurchased'] = $this->data->anonymize('skybook_gift_voucher', 'purchaser_email', $email, ['purchaser_name' => 'Supprime RGPD', 'purchaser_email' => $token, 'personal_message' => null]);
            $counts['vouchersReceived'] = $this->data->anonymize('skybook_gift_voucher', 'beneficiary_email', $email, ['beneficiary_name' => 'Supprime RGPD', 'beneficiary_email' => $token, 'personal_message' => null]);
            $counts['customers'] = $this->data->anonymize('sylius_customer', 'email', $email, ['first_name' => 'Supprime', 'last_name' => 'RGPD', 'email' => $token, 'email_canonical' => $token, 'phone_number' => null, 'birthday' => null]);
            $counts['accounts'] = $this->data->anonymize('sylius_shop_user', 'username', $email, ['username' => $token, 'username_canonical' => $token, 'enabled' => false, 'password' => bin2hex(random_bytes(32)), 'password_reset_token' => null, 'verified_at' => null]);
            $this->persistAudit('erase', $email, $actor, ['reason' => $reason, 'counts' => $counts]);
        });
        $this->entityManager->clear();

        return $counts;
    }

    /** @return array<string, int> */
    public function purge(\DateTimeImmutable $now, string $actor, bool $dryRun): array
    {
        $connection = $this->entityManager->getConnection();
        $bookingCutoff = $this->policy->bookingCutoff($now)->format('Y-m-d H:i:s');
        $waitlistCutoff = $this->policy->waitlistCutoff($now)->format('Y-m-d H:i:s');
        $counts = $this->data->retentionCounts($bookingCutoff, $waitlistCutoff);
        if ($dryRun) {
            return $counts;
        }

        $connection->transactional(function () use ($bookingCutoff, $waitlistCutoff, $actor, &$counts): void {
            $counts = $this->data->purgeExpired($bookingCutoff, $waitlistCutoff, $counts);
            $this->persistAudit('retention_purge', null, $actor, ['counts' => $counts, 'policy' => $this->policy->describe()]);
        });
        $this->entityManager->clear();

        return $counts;
    }

    private function audit(string $action, string $email, string $actor, array $details): void
    {
        $this->persistAudit($action, $email, $actor, $details);
        $this->entityManager->flush();
    }

    private function persistAudit(string $action, ?string $email, string $actor, array $details): void
    {
        $this->entityManager->persist(new GdprAuditLog(bin2hex(random_bytes(32)), $action, $email === null ? null : hash('sha256', $this->tenantContext->getSlug().'|'.$email), mb_substr($actor, 0, 180), $details));
        $this->entityManager->flush();
    }

    private function email(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Adresse email invalide.');
        return $email;
    }
}
