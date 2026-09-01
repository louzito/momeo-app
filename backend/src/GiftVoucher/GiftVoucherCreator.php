<?php

declare(strict_types=1);

namespace App\GiftVoucher;

use App\Entity\GiftVoucher;
use App\Repository\GiftVoucherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Cree le GiftVoucher (statut awaiting_payment) pour une commande d'achat
 * cadeau completee. Appele par App\EventListener\CreateGiftVoucherOnOrderPlacedListener
 * (checkoutState=completed), comme sylius/invoicing-plugin cree la facture au
 * meme moment (CreateInvoiceOnOrderPlacedListener -> InvoiceCreator). Toutes
 * les infos "produit" (jumpType, montant, devise, acheteur) sont DENORMALISEES
 * depuis la commande elle-meme ; seules les infos beneficiaire viennent du
 * marqueur (App\GiftVoucher\GiftOrderMarker, pose via l'API avant le complete).
 *
 * Idempotent : ne recree pas de cheque si un cheque existe deja pour ce
 * numero de commande d'achat (postPersist ET postUpdate peuvent tous deux
 * matcher checkoutState=completed selon comment l'ORM detecte le changement).
 */
final class GiftVoucherCreator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GiftVoucherRepository $giftVoucherRepository,
        private readonly GiftVoucherCodeGenerator $codeGenerator,
        private readonly GiftVoucherConfig $config,
    ) {
    }

    public function createFromOrder(OrderInterface $order, GiftOrderMarker $marker): ?GiftVoucher
    {
        $orderNumber = $order->getNumber();
        if ($orderNumber === null || $this->giftVoucherRepository->findOneByPurchaseOrderNumber($orderNumber) !== null) {
            return null;
        }

        $item = $order->getItems()->first();
        if ($item === false) {
            return null;
        }
        $variant = $item->getVariant();
        $productCode = $variant?->getProduct()?->getCode();
        if ($productCode === null) {
            return null;
        }

        $customer = $order->getCustomer();
        $billingAddress = $order->getBillingAddress();
        $purchaserName = trim(sprintf(
            '%s %s',
            $billingAddress?->getFirstName() ?? '',
            $billingAddress?->getLastName() ?? '',
        ));

        $voucher = new GiftVoucher();
        $voucher->setCode($this->codeGenerator->generate());
        $voucher->setStatus(GiftVoucher::STATUS_AWAITING_PAYMENT);
        $voucher->setServiceCode($productCode);
        $voucher->setServiceName($item->getProductName() ?? $productCode);
        $voucher->setAmount($order->getTotal());
        $voucher->setCurrencyCode($order->getCurrencyCode() ?? 'EUR');
        $voucher->setPurchaserName($purchaserName !== '' ? $purchaserName : ($customer?->getEmail() ?? ''));
        $voucher->setPurchaserEmail($customer?->getEmail() ?? '');
        $voucher->setBeneficiaryName($marker->beneficiaryName);
        $voucher->setBeneficiaryEmail($marker->beneficiaryEmail);
        $voucher->setPersonalMessage($marker->personalMessage);
        $voucher->setPurchaseOrderNumber($orderNumber);
        $voucher->setExpiresAt((new \DateTimeImmutable())->modify('+' . $this->config->validityMonths() . ' months'));

        $this->em->persist($voucher);
        $this->em->flush();

        return $voucher;
    }
}
