<?php

declare(strict_types=1);

namespace App\EventListener;

/**
 * SkyBook — LISTENER NEUTRALISE (garde comme documentation).
 *
 * Historique : on croyait que sylius/invoicing-plugin ne branchait que winzou
 * (inoperant sur Sylius 2) et on avait ajoute ici un listener applicatif sur
 * `workflow.sylius_payment.completed.complete` appelant
 * `sylius_invoicing.event_producer.order_payment_paid`.
 *
 * VERIFIE EN LISANT LE PLUGIN v2.2.0 + TEST LIVE (commande 000000027) :
 *
 * 1. Le plugin enregistre DEJA son propre listener symfony workflow sur ce
 *    meme evenement (`Sylius\InvoicingPlugin\EventListener\Workflow\Payment\
 *    ProduceOrderPaymentPaidListener`, cf. config/services/listeners/workflow.xml,
 *    priorite 50). Notre doublon provoquait DEUX events OrderPaymentPaid,
 *    donc l'email de facture etait envoye DEUX FOIS (constate dans MailHog).
 *
 * 2. Surtout, la FACTURE n'est PAS creee au paiement : elle est creee des que
 *    la commande passe en checkoutState=completed (listener Doctrine
 *    OrderPlacedProducer -> event OrderPlaced -> CreateInvoiceOnOrderPlacedListener).
 *    L'event paiement (OrderPaymentPaid) ne fait qu'ENVOYER L'EMAIL, et
 *    uniquement si une facture existe deja pour la commande.
 *
 * Consequence : plus aucun code custom n'est necessaire ici. Les commandes
 * passees AVANT l'installation du plugin n'ont pas de facture -> utiliser
 * `bin/console sylius-invoicing:generate-invoices` pour les rattraper.
 *
 * La classe est conservee vide (sans attribut AsEventListener) uniquement
 * pour laisser cette note a portee de main ; elle peut etre supprimee.
 */
final class CreateInvoiceOnPaymentCompletedListener
{
}
