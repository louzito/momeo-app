<?php

declare(strict_types=1);

namespace App\Tenant;

use App\Entity\Addressing\Country;
use App\Entity\Addressing\Zone;
use App\Entity\Addressing\ZoneMember;
use App\Entity\Channel\Channel;
use App\Entity\Currency\Currency;
use App\Entity\Locale\Locale;
use App\Entity\Payment\GatewayConfig;
use App\Entity\Payment\PaymentMethod;
use App\Entity\Shipping\ShippingMethod;
use App\Entity\User\AdminUser;
use App\Security\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;

/** Creates the small, production-safe Sylius baseline required by a tenant. */
final class MinimalSyliusInitializer
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function initialize(string $tenantName, ?string $adminEmail, ?string $adminPassword = null): ?string
    {
        $currency = $this->currency();
        $locale = $this->locale();
        $country = $this->country();
        $zone = $this->zone($country);
        $channel = $this->channel($tenantName, $adminEmail, $currency, $locale);
        $this->paymentMethod($channel);
        $this->stripePaymentMethod($channel);
        $this->shippingMethod($channel, $zone);
        $password = $this->admin($adminEmail, $adminPassword);

        $this->entityManager->flush();

        return $password;
    }

    private function currency(): Currency
    {
        $currency = $this->entityManager->getRepository(Currency::class)->findOneBy(['code' => 'EUR']);
        if (!$currency instanceof Currency) {
            $currency = new Currency();
            $currency->setCode('EUR');
            $this->entityManager->persist($currency);
        }

        return $currency;
    }

    private function locale(): Locale
    {
        $locale = $this->entityManager->getRepository(Locale::class)->findOneBy(['code' => 'fr_FR']);
        if (!$locale instanceof Locale) {
            $locale = new Locale();
            $locale->setCode('fr_FR');
            $this->entityManager->persist($locale);
        }

        return $locale;
    }

    private function country(): Country
    {
        $country = $this->entityManager->getRepository(Country::class)->findOneBy(['code' => 'FR']);
        if (!$country instanceof Country) {
            $country = new Country();
            $country->setCode('FR');
            $this->entityManager->persist($country);
        }
        $country->setEnabled(true);

        return $country;
    }

    private function zone(Country $country): Zone
    {
        $zone = $this->entityManager->getRepository(Zone::class)->findOneBy(['code' => 'FR']);
        if (!$zone instanceof Zone) {
            $zone = new Zone();
            $zone->setCode('FR');
            $zone->setName('France');
            $zone->setType(ZoneInterface::TYPE_COUNTRY);
            $this->entityManager->persist($zone);
        }

        $member = $this->entityManager->getRepository(ZoneMember::class)->findOneBy([
            'belongsTo' => $zone,
            'code' => $country->getCode(),
        ]);
        if (!$member instanceof ZoneMember) {
            $member = new ZoneMember();
            $member->setCode((string) $country->getCode());
            $zone->addMember($member);
        }

        return $zone;
    }

    private function channel(string $name, ?string $email, Currency $currency, Locale $locale): Channel
    {
        $channel = $this->entityManager->getRepository(Channel::class)->findOneBy(['code' => 'FASHION_WEB']);
        if (!$channel instanceof Channel) {
            $channel = new Channel();
            // Kept for compatibility with the existing shop and provisioning clients.
            $channel->setCode('FASHION_WEB');
            $this->entityManager->persist($channel);
        }
        $channel->setName($name);
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        if ($email !== null) {
            $channel->setContactEmail($email);
        }

        return $channel;
    }

    private function paymentMethod(Channel $channel): void
    {
        $method = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'bank_transfer']);
        if (!$method instanceof PaymentMethod) {
            $gateway = new GatewayConfig();
            $gateway->setGatewayName('bank_transfer');
            $gateway->setFactoryName('offline');
            $gateway->setConfig([]);

            $method = new PaymentMethod();
            $method->setCode('bank_transfer');
            $method->setGatewayConfig($gateway);
            $method->setCurrentLocale('fr_FR');
            $method->setFallbackLocale('fr_FR');
            $method->setName('Virement bancaire');
            $this->entityManager->persist($gateway);
            $this->entityManager->persist($method);
        }
        $method->setEnabled(true);
        $method->addChannel($channel);
    }

    private function stripePaymentMethod(Channel $channel): void
    {
        $method = $this->entityManager->getRepository(PaymentMethod::class)->findOneBy(['code' => 'stripe_web_elements']);
        if (!$method instanceof PaymentMethod) {
            $gateway = new GatewayConfig();
            $gateway->setGatewayName('stripe_web_elements');
            $gateway->setFactoryName('stripe_web_elements');
            // Secrets are entered in the administration and encrypted by Sylius.
            $gateway->setConfig([]);

            $method = new PaymentMethod();
            $method->setCode('stripe_web_elements');
            $method->setGatewayConfig($gateway);
            $method->setCurrentLocale('fr_FR');
            $method->setFallbackLocale('fr_FR');
            $method->setName('Carte bancaire');
            $method->setEnabled(false);
            $this->entityManager->persist($gateway);
            $this->entityManager->persist($method);
        }
        $method->addChannel($channel);
    }

    private function shippingMethod(Channel $channel, Zone $zone): void
    {
        $method = $this->entityManager->getRepository(ShippingMethod::class)->findOneBy(['code' => 'standard']);
        if (!$method instanceof ShippingMethod) {
            $method = new ShippingMethod();
            $method->setCode('standard');
            $method->setCalculator('flat_rate');
            $method->setConfiguration(['amount' => 0]);
            $method->setCurrentLocale('fr_FR');
            $method->setFallbackLocale('fr_FR');
            $method->setName('Livraison standard');
            $this->entityManager->persist($method);
        }
        $method->setEnabled(true);
        $method->setZone($zone);
        $method->addChannel($channel);
    }

    private function admin(?string $email, ?string $providedPassword): ?string
    {
        if ($email === null) {
            return null;
        }
        if ($providedPassword !== null && strlen($providedPassword) < 16) {
            throw new \InvalidArgumentException('Le mot de passe administrateur doit contenir au moins 16 caractères.');
        }

        $repository = $this->entityManager->getRepository(AdminUser::class);
        $admin = $repository->findOneBy(['email' => $email]);
        foreach ($repository->findAll() as $otherAdmin) {
            if ($otherAdmin instanceof AdminUser && $otherAdmin !== $admin) {
                // A template account must never remain usable in a claimed tenant.
                $otherAdmin->setEnabled(false);
            }
        }
        if ($admin instanceof AdminUser) {
            $admin->setEnabled(true);
            $admin->setTeamRole(TeamRole::Owner);
            if ($providedPassword !== null) {
                $admin->setPlainPassword($providedPassword);
            }

            return null;
        }

        $password = $providedPassword ?? rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $admin = new AdminUser();
        $admin->setEmail($email);
        $admin->setUsername($email);
        $admin->setPlainPassword($password);
        $admin->setEnabled(true);
        $admin->setLocaleCode('fr_FR');
        $admin->setTeamRole(TeamRole::Owner);
        $this->entityManager->persist($admin);

        return $password;
    }
}
