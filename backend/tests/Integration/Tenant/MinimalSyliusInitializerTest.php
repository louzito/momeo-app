<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenant;

use App\Entity\Addressing\Country;
use App\Entity\Addressing\Zone;
use App\Entity\Channel\Channel;
use App\Entity\Currency\Currency;
use App\Entity\Locale\Locale;
use App\Entity\Payment\PaymentMethod;
use App\Entity\Shipping\ShippingMethod;
use App\Entity\User\AdminUser;
use App\Tenant\MinimalSyliusInitializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MinimalSyliusInitializerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testItCreatesTheMinimalDataAndCanBeRunTwiceWithoutDuplicates(): void
    {
        /** @var MinimalSyliusInitializer $initializer */
        $initializer = self::getContainer()->get(MinimalSyliusInitializer::class);
        $email = sprintf('provisioning-%s@example.test', bin2hex(random_bytes(8)));

        $password = $initializer->initialize('Centre de test', $email);
        $secondPassword = $initializer->initialize('Centre de test', $email);

        self::assertNotNull($password);
        self::assertGreaterThanOrEqual(32, strlen($password));
        self::assertNull($secondPassword);
        self::assertSame(1, $this->count(Currency::class, ['code' => 'EUR']));
        self::assertSame(1, $this->count(Locale::class, ['code' => 'fr_FR']));
        self::assertSame(1, $this->count(Country::class, ['code' => 'FR']));
        self::assertSame(1, $this->count(Zone::class, ['code' => 'FR']));
        self::assertSame(1, $this->count(Channel::class, ['code' => 'FASHION_WEB']));
        self::assertSame(1, $this->count(PaymentMethod::class, ['code' => 'bank_transfer']));
        self::assertSame(1, $this->count(ShippingMethod::class, ['code' => 'standard']));
        self::assertSame(1, $this->count(AdminUser::class, ['email' => $email]));
    }

    /** @param class-string $class @param array<string, mixed> $criteria */
    private function count(string $class, array $criteria): int
    {
        return $this->entityManager->getRepository($class)->count($criteria);
    }
}
