<?php

declare(strict_types=1);

namespace App\EventListener;

use App\GiftVoucher\GiftOrderMarker;
use App\GiftVoucher\GiftVoucherCreator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Webmozart\Assert\Assert;

/**
 * Cheques cadeaux reels — creation du GiftVoucher (awaiting_payment) des
 * qu'une commande marquee cadeau (App\GiftVoucher\GiftOrderMarker dans
 * Order::$notes) passe en checkoutState=completed. MEME PATTERN que
 * sylius/invoicing-plugin (Sylius\InvoicingPlugin\EventProducer\OrderPlacedProducer) :
 * postPersist couvre une commande deja completee des sa creation, postUpdate
 * detecte la transition via le changeset Doctrine (evite de reagir a chaque
 * flush qui ne touche pas checkoutState). Idempotence geree dans
 * GiftVoucherCreator (verifie qu'aucun cheque n'existe deja pour ce numero
 * de commande).
 */
#[AsDoctrineListener(event: 'postPersist')]
#[AsDoctrineListener(event: 'postUpdate')]
final class CreateGiftVoucherOnOrderPlacedListener
{
    public function __construct(private readonly GiftVoucherCreator $giftVoucherCreator)
    {
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $order = $event->getObject();
        if (!$order instanceof OrderInterface || $order->getCheckoutState() !== OrderCheckoutStates::STATE_COMPLETED) {
            return;
        }
        $this->handle($order);
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $order = $event->getObject();
        if (!$order instanceof OrderInterface) {
            return;
        }

        $entityManager = $event->getObjectManager();
        Assert::isInstanceOf($entityManager, EntityManagerInterface::class);
        $changeSet = $entityManager->getUnitOfWork()->getEntityChangeSet($order);

        if (!isset($changeSet['checkoutState']) || $changeSet['checkoutState'][1] !== OrderCheckoutStates::STATE_COMPLETED) {
            return;
        }
        $this->handle($order);
    }

    private function handle(OrderInterface $order): void
    {
        $marker = GiftOrderMarker::decode($order->getNotes());
        if ($marker === null) {
            return;
        }
        $this->giftVoucherCreator->createFromOrder($order, $marker);
    }
}
