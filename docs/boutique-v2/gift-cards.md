# Cartes cadeaux à solde — ticket 118

## Périmètre et compatibilité

Aucune dépendance dans la série pour cette étape. Les nouvelles entités `GiftCard`
et `GiftCardMovement` sont distinctes de `GiftVoucher`. Aucun bon existant n’est
converti, supprimé ou réinitialisé ; les endpoints, codes et parcours des bons
prestation restent inchangés.

La migration `Version20260927180000` est additive et doit être exécutée par le
processus habituel sur les bases des établissements et le modèle de provisionnement.
Elle n’a pas été appliquée par l’agent. Le retour arrière destructif est interdit.

## Contrat des services pour les étapes suivantes

- Le futur parcours d’achat prépare une **commande dédiée**, avec
  `Order::setGiftCardAmount()` égal au total en centimes. Ce champ n’est pas exposé
  en écriture par une nouvelle API publique. Il ne faut pas utiliser le marqueur
  `SKYBOOK_GIFT`, réservé aux anciens bons. Le montant doit être déterminé et validé
  côté serveur avant finalisation du panier. Ce ticket n’ajoute pas le parcours
  public de vente ou d’application du crédit.
- Le listener de paiement appelle `issueFromPayment` après encaissement. Aucun
  crédit n’est émis pour un acompte ; les paiements complétés dans la devise de la
  commande doivent couvrir le total. Une seule carte est émise par commande, avec
  un mouvement d’émission, un code aléatoire de 128 bits et une validité d’un an.
- `consult(code, channel, currency)` est une consultation **interne** à la boutique
  courante. Une future API client doit vérifier les droits du porteur et protéger
  la saisie du code ; aucune recherche publique n’est ajoutée ici.
- `reserve(code, orderId, amount)` immobilise le crédit, puis
  `debit(code, orderId)` consomme **toute cette réservation**. Le futur orchestrateur
  de checkout doit appeler le débit et finaliser sa commande dans la même
  transaction. Ces services ne créent ni paiement Sylius ni ajustement de prix.
- `release(code, orderId)` libère la réservation. Les transitions Sylius de paiement
  `fail` et `cancel` appellent déjà `releaseForOrder`. Les échecs survenant avant la
  création d’un paiement doivent appeler cette même méthode dans l’orchestrateur.
- Une réservation par couple carte/commande : débit et libération sont exclusifs.
  Une réservation libérée est terminale ; un nouvel essai de consommation nécessite
  une nouvelle commande. Les rejeux rendent le mouvement original, sans mutation.
  Un montant différent pour la même opération est refusé.
- `refund(code, orderId, amount, refundKey)` restitue partiellement ou totalement
  le débit de cette commande, au maximum du montant restant à restituer. La clé
  stable doit identifier le remboursement métier, indépendamment des retries HTTP.
  Elle est conservée dans l’historique. Aucune restitution en espèces n’est créée
  par ce service : il recrédite uniquement la carte.

## Invariants

Les montants sont des entiers en centimes, strictement positifs. Disponible et
réservé restent positifs ou nuls et leur somme ne dépasse jamais le montant initial.
Les services verrouillent d’abord la commande, puis la carte, et rafraîchissent le
solde sous verrou pessimiste ; solde et mouvement sont validés ensemble par Doctrine.
Les références des commandes et la contrainte unique carte/opération garantissent
l’idempotence. Le journal sert à la traçabilité et au contrôle des restitutions,
pas à reconstruire le solde (pas d’event sourcing).

La base tenant, le slug d’établissement, le canal et la devise de la carte doivent
correspondre à la commande. L’achat d’une carte ou d’un ancien bon par ce crédit est
refusé. Une carte inactive ou expirée ne peut ni réserver ni débiter. Une libération
ou restitution reste possible après expiration/inactivation, **sans renouveler la
validité ni réactiver la carte**. Un solde nul reste une carte active sans crédit
disponible. Aucun cron ne détruit le solde à expiration.

## Administration et vérifications

L’écran existant des chèques cadeaux affiche une section dédiée aux cartes à solde,
avec pagination et historique. Les endpoints `/api/v2/admin/gift-cards` et
`/api/v2/admin/gift-cards/{id}/movements` héritent du firewall admin, de l’isolation
JWT/tenant et de la permission Finances.

Tests ciblés (dépendances installées et base de test migrée) :

```sh
cd backend
vendor/bin/phpunit -c phpunit.business.xml --filter 'GiftCard|GiftVoucher|AdminApiPermissionContractTest'
cd ../frontend
npm run build
```

Le test `GiftCardConcurrencyTest` lance deux processus utilisant le service réel,
avec 100 € de crédit et deux demandes de 70 € ; il exige MySQL/InnoDB. Il vérifie
l’attente sur le verrou, le refus de la seconde réservation et le rejeu du débit
après relecture depuis la base.

### Contrôles effectués dans l’environnement du ticket

- Syntaxe PHP des fichiers ajoutés/modifiés et `git diff --check` : réussis.
- Exécution PHP directe des invariants des entités : réussie (100 € moins 70 €,
  solde insuffisant, libération, restitution plafonnée, établissement/canal/devise,
  expiration, inactivation, instantané de mouvement et utilisabilité d’un ancien bon).
- PHPUnit, test concurrent InnoDB et validation du mapping/migration : non exécutés,
  car `backend/vendor` est absent et `composer install` échoue à résoudre GitHub.
- Build Vue : non exécuté, Node/npm absents. Aucun aperçu visuel n’a été validé.
