<?php
namespace App\Service\Commerce;
use App\Entity\Order\Order;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
/** The product fulfillment fee replaces the generic shipping rate in a unified cart. */
#[AsDecorator(decorates: 'sylius.order_processing.shipping_charges_processor')]
final class UnifiedShippingProcessor implements OrderProcessorInterface
{
    public function __construct(private readonly OrderProcessorInterface $inner) {}
    public function process(OrderInterface $order): void
    {
        if (!$order instanceof Order || $order->getCheckoutKey() === null) { $this->inner->process($order); return; }
        if ($order->getCheckoutState() === 'completed') return;
        foreach ($order->getShipments() as $shipment) $shipment->removeAdjustments('shipping');
    }
}
