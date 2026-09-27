<?php

declare(strict_types=1);

namespace App\Tests\GiftCard;

use App\Entity\Channel\Channel;
use App\Entity\GiftCard;
use App\Entity\Order\Order;
use App\Entity\Order\OrderItem;
use App\Service\GiftCard\GiftCardService;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Exécute le service réel dans deux processus, sur une base de test migrée InnoDB. */
final class GiftCardConcurrencyTest extends KernelTestCase
{
    public function testTwoOrdersCannotSpendMoreThanTheCardBalance(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        if ($db->getDatabasePlatform()->getName() !== 'mysql') self::markTestSkipped('Ce test nécessite InnoDB.');
        $channel = $em->getRepository(Channel::class)->findOneBy([]);
        if (!$channel instanceof Channel) self::markTestSkipped('Un canal de test est nécessaire.');
        $tenant = self::getContainer()->get(TenantContext::class);
        $suffix = bin2hex(random_bytes(8));
        $card = new GiftCard($tenant->getSlug(), $channel->getCode(), 'EUR', 10000, 'CARD-'.$suffix, new \DateTimeImmutable('+1 year'));
        $em->persist($card);
        $orders = [];
        foreach (['A', 'B'] as $name) {
            $order = new Order();
            $order->setNumber('GC-'.$name.'-'.$suffix);
            $order->setTokenValue(bin2hex(random_bytes(16)));
            $order->setChannel($channel);
            $order->setCurrencyCode('EUR');
            $order->setLocaleCode('fr_FR');
            $item = new OrderItem();
            $item->setUnitPrice(10000);
            $item->setQuantity(1);
            $order->addItem($item);
            $em->persist($order);
            $orders[] = $order;
        }
        $em->flush();
        $service = self::getContainer()->get(GiftCardService::class);
        $process = null;
        $pipes = [];
        try {
            $db->beginTransaction();
            $service->reserve($card->getCode(), $orders[0]->getId(), 7000);
            $script = <<<'CHILD'
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';
require $argv[1].'/tests/bootstrap.php';
$kernel = new App\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$container->get(App\Service\Tenant\TenantContext::class)->setSlug($argv[4]);
$service = $container->get(App\Service\GiftCard\GiftCardService::class);
echo "ready\n";
flush();
try {
    $service->reserve($argv[2], (int) $argv[3], 7000);
    echo 'overspent';
} catch (DomainException $error) {
    echo $error->getMessage() === 'Le solde disponible est insuffisant.' ? 'insufficient' : 'unexpected:'.$error->getMessage();
}
CHILD;
            $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2), $card->getCode(), (string) $orders[1]->getId(), $tenant->getSlug()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            stream_set_timeout($pipes[1], 30);
            self::assertSame("ready\n", fgets($pipes[1]));
            usleep(200000);
            self::assertTrue(proc_get_status($process)['running'], 'La seconde commande doit attendre le verrou.');
            $service->debit($card->getCode(), $orders[0]->getId());
            $db->commit();
            $output = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            $exit = proc_close($process);
            $process = null;
            self::assertSame(0, $exit, $stderr);
            self::assertSame('insufficient', $output);
            // Relecture et rejeu après commit, pas seulement dans l’identity map.
            $em->clear();
            $service->debit($card->getCode(), $orders[0]->getId());
            self::assertSame(3000, (int) $db->fetchOne('SELECT available FROM todatempo_gift_card WHERE id = ?', [$card->getId()]));
            self::assertSame(0, (int) $db->fetchOne('SELECT reserved FROM todatempo_gift_card WHERE id = ?', [$card->getId()]));
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM todatempo_gift_card_movement WHERE card_id = ?', [$card->getId()]));
        } finally {
            if ($db->isTransactionActive()) $db->rollBack();
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            $db->executeStatement('DELETE FROM todatempo_gift_card_movement WHERE card_id = ?', [$card->getId()]);
            $db->executeStatement('DELETE FROM todatempo_gift_card WHERE id = ?', [$card->getId()]);
            foreach ($orders as $order) {
                $db->executeStatement('DELETE FROM sylius_order_item WHERE order_id = ?', [$order->getId()]);
                $db->executeStatement('DELETE FROM sylius_order WHERE id = ?', [$order->getId()]);
            }
        }
    }
}
