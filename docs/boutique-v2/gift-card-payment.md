# Utilisation de la carte cadeau — ticket #121

## Parcours et montants

Les checkouts prestation et produit proposent « Utiliser une carte cadeau ».
La consultation est limitée à l’établissement courant et protégée par la
limitation de requêtes existante. Les codes restent dans les corps HTTP.
Le montant affiché est vérifié de nouveau côté serveur avant tout mouvement.
Une seule carte est acceptée par commande, sans financer un autre cadeau.

Le serveur prépare le moyen de paiement après détermination de l’acompte ou des
frais de remise. Le checkout Sylius doit ensuite réussir (notamment le stock),
et une prestation doit avoir sa réservation persistée avant le règlement cadeau.
Le frontend conserve le résultat commercial avant de régler le cadeau : une
reprise du règlement réutilise le même jeton et ne crée pas une deuxième commande.

La contribution cadeau est un paiement Sylius séparé. Aucun ajustement de remise
ne modifie le prix. Une couverture intégrale est encaissée localement sans Stripe.
Sinon, le crédit est réservé et seul le complément est envoyé à Stripe. Le
webhook signé encaisse ensuite la contribution cadeau dans la transaction du
paiement bancaire. Les mouvements idempotents et les verrous commande/carte
existants empêchent le double débit et le dépassement de solde.

Pour un acompte, seul le montant dû maintenant est couvert ; le solde ultérieur
reste intact et visible. La confirmation et le compte affichent la répartition.
La désactivation des ventes cadeaux n’empêche pas l’utilisation des cartes émises.

## Échecs, abandon et remboursements

Un créneau ou un stock refusé n’a encore réservé aucun crédit cadeau. Un échec
ou une expiration Stripe libère le crédit par le webhook signé. La même
restitution répétée ne recrédite pas deux fois la carte. Une confirmation tardive
peut honorer du crédit réservé avant l’expiration de la carte ; une carte
explicitement désactivée reste bloquée.

Sans démarrage de session Stripe, le crédit réservé expire après une heure.
Déployer les unités `backend/etc/systemd/todatempo-gift-card-expiry@.*` avec le
reste de l’application, par établissement. La commande exige un tenant explicite
valide. Une fois qu’une session Stripe peut exister, le timer ne libère pas le
crédit sur la seule horloge locale : le webhook fait foi, afin de ne pas restituer
un crédit correspondant à un paiement déjà encaissé mais pas encore notifié.
En cas d’échec réseau indéterminé de création de session, reprendre le paiement
avec le même jeton/idempotency key ; ne pas libérer manuellement le crédit sans
vérifier Stripe. Les webhooks et workers restent nécessaires à l’exploitation.

Les remboursements suivent l’action explicite existante de l’administration.
Le paiement cadeau utilise `GiftCardService::refund` et ne contacte pas Stripe ;
le paiement bancaire conserve son fournisseur habituel. Chaque part reste bornée
par son montant encaissé et ses restitutions antérieures. Rembourser toute la
part cadeau d’un paiement mixte ne marque pas la part bancaire comme remboursée.
L’annulation d’un rendez-vous ne déclenche pas un remboursement bancaire implicite,
conformément au parcours existant.

## Validation de la reprise du 28/09/2026

- 120 tests backend ciblés, 850 assertions : cartes cadeaux, workflows Sylius,
  Stripe, webhooks signés rejoués, remboursements, projections de réservation,
  contrôles du checkout physique et règles de réservation. MySQL 8.4 isolé.
- La concurrence est exercée par deux processus réels sur la même carte InnoDB.
- Les tests d’intégration couvrent 100/70, 50/80, montant exact, acompte d’une
  prestation, échec, abandon, remboursement séparé, autre établissement et
  webhook tardif. Appels Stripe et moteur PDF remplacés uniquement aux frontières
  externes des tests ; aucun paiement bancaire réel ni SMTP réel.
- 56 tests frontend réussis ; build Vue de production réussi.
- Deux fixtures préexistantes utilisaient `OrderItem::setQuantity`, absent de
  Sylius installé : correction pour utiliser ses unités réelles. Mise à jour des
  attentes de projection et du test de route de confirmation devenu obsolète.
- Dépréciations de dépendances observées, sans erreur ni échec des tests ciblés.
- Aucun déploiement ni migration de base métier effectué dans cette reprise.
