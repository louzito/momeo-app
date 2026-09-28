# Achat de carte cadeau — ticket 120

## Dépendances vérifiées

Les livrables de #118 (`2f624a0`) et #119 (`ef31208`) sont présents dans l’historique
et dans le code fourni. Leurs migrations et leur fonctionnement en production
n’ont pas été vérifiés : aucune connexion à une base ni aucun déploiement effectué.

## Livrables

- Page indépendante `/{établissement}/gift-card`, accessible depuis la navigation
  mobile/desktop, le pied de page, le catalogue et le bandeau d’accueil.
- Montants de 50/100 € ou de 10 à 1 000 € au centime près. Le serveur exige un
  entier en centimes et refuse les coordonnées invalides. Le message est limité
  à 1 000 caractères et les noms à 100 caractères.
- Nom/e-mail de l’acheteur, nom du destinataire, message facultatif. L’e-mail du
  destinataire est obligatoire uniquement pour un envoi direct. L’envoi à
  l’acheteur ne provoque aucun envoi au destinataire.
- `GiftCardCheckoutService` crée une commande Sylius dédiée, avec ajustement
  monétaire verrouillé et paiement intégral Stripe ou virement activé sur le canal.
  Aucun produit, créneau, stock ou Booking n’est créé. Le compte d’un acheteur
  existant n’est jamais modifié par cet endpoint public.
- La durée configurée, les coordonnées et le choix de remise sont conservés dans
  `Order.giftCardPurchase`. Les URL sont produites côté serveur par le générateur
  tenant ; aucune URL ni durée provenant du client n’est acceptée.
- Le récapitulatif affiche montant payé/crédit, établissement, validité et remise.
  Le suivi de paiement réaffiche les conditions enregistrées côté serveur.
- La carte n’existe qu’après paiement confirmé pour exactement le montant prévu.
  L’émission conserve le verrou et la contrainte unique du ticket 118. Le même
  e-mail contient code, montant, validité, message échappé, boutique et lien vers
  le document imprimable. Aucun second envoi de document indépendant n’est créé.
- `POST /shop/gift-cards/document` exige le code aléatoire de 128 bits et filtre
  l’établissement. Il ne restitue pas les e-mails ou le token de commande. Le
  code est dans le fragment du lien imprimable, puis le corps HTTP, sans cache.
  La limitation existante des requêtes sensibles couvre achat et document.
- La désactivation des ventes est contrôlée côté serveur. La lecture d’une carte
  émise et les parcours des anciens `GiftVoucher` restent accessibles.

Migration additive : `Version20260927223000`, à appliquer par le processus habituel
aux bases des établissements et au modèle de provisionnement. Aucune donnée
existante n’est convertie ou effacée. Migration non exécutée par l’agent.

Comme pour #119, la garantie d’enfilement transactionnel suppose le transport
Mailer/Messenger existant `MESSENGER_TRANSPORT_DSN=doctrine://default`, sur la même
connexion tenant, et le worker tenant. Les doublons de confirmation n’enfilent
pas de second e-mail. La livraison SMTP effective dépend du transport et de ses
retries ; aucune garantie d’exactement une livraison par un serveur SMTP externe
n’est revendiquée.

## Périmètre validé

L’achat, l’émission et les documents appartiennent au ticket #120. La saisie et
l’utilisation du code dans les deux checkouts appartiennent au ticket #121,
qui dépend de #120. Ce raccordement ne constitue donc pas un prérequis de #120.

## Vérifications de la reprise du 28/09/2026

- 51 tests ciblés PHPUnit réussis, 259 assertions : achat, émission, e-mail,
  invariants, réservation/restitution de crédit et paiement Stripe.
- Build Vue de production réussi (Vite).
- `git diff --check` réussi.
- La première exécution élargie a révélé une configuration de base de test
  absente ; les tests d’intégration MySQL ne sont pas inclus dans les 51 tests.
- Migration livrée ; aucun déploiement ni paiement réel effectué.
