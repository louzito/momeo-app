# Paiement des cadeaux et produits — ticket 119

## Dépendance et périmètre

La dépendance #118 est intégrée dans le checkout fourni : commit `2f624a0`,
entités GiftCard/GiftCardMovement, service d’émission et migration additive.
La migration reste à appliquer par le processus habituel si elle ne l’est pas
encore sur les bases des établissements. Aucune migration, donnée ou branche
n’a été modifiée par ce ticket.

Les cadeaux prestation existants et les produits physiques proposent les moyens
activés dans le shop. Stripe utilise leur vraie commande Sylius sans Booking.
Les commandes monétaires marquées côté serveur par `Order::setGiftCardAmount`
utilisent le même service Stripe ; le parcours public de vente de ces nouvelles
cartes reste celui des étapes suivantes, conformément au contrat du ticket 118.

Le montant Stripe vient du paiement Sylius et doit couvrir exactement le restant
dû : total de commande moins les autres paiements complétés dans sa devise.
C’est le contrat pour un futur paiement complémentaire après crédit cadeau :
l’orchestrateur devra matérialiser le crédit dans les paiements Sylius avant
l’appel Stripe, et gérer son remboursement/libération si nécessaire. Ce ticket
n’ajoute pas de formulaire public de saisie de carte cadeau ni de consommation
implicite de solde. Une commande d’achat de carte monétaire doit toujours être
réglée intégralement en argent. Les cadeaux ne reçoivent aucun ajustement d’acompte.

## Confirmation et notifications

- `checkout-session` accepte un booking facultatif, avec contrôle de son lien à
  la commande lorsqu’il est fourni. Une prestation ordinaire continue de l’exiger.
- Le moyen doit être Stripe, activé et rattaché au canal de commande. Le paiement
  et les montants viennent de Doctrine ; les URL de retour restent sur l’hôte
  courant. La clé d’idempotence contient le token de commande et le paiement,
  pour éviter les collisions d’identifiants entre établissements.
- Le webhook conserve la signature Stripe et l’unicité de l’événement. Il
  verrouille/rafraîchit commande puis paiement, vérifie le token, la devise,
  le montant et le moyen, puis applique le workflow Sylius une seule fois.
  Le doublon d’événement et un second événement pour un paiement déjà encaissé
  ne réexécutent pas les notifications. Expiration/échec tardifs ne dégradent
  pas un paiement complété. Désactiver Stripe n’empêche pas de traiter une
  notification concernant un paiement déjà démarré.
- La transition déclenche les listeners existants : anciens bons activés après
  règlement complet, cartes monétaires émises une seule fois après paiement
  complet. L’e-mail de carte monétaire est déclenché dans la transaction d’émission,
  uniquement pour une nouvelle carte, à l’acheteur de la commande dédiée.
  Les anciens bons conservent leurs destinataires acheteur/bénéficiaire.
- L’envoi utilise le mailer et le transport Messenger existants. En production,
  conserver `MESSENGER_TRANSPORT_DSN=doctrine://default` et le worker tenant :
  l’enregistrement de l’e-mail doit partager la transaction de la base tenant.
  Ne pas utiliser un transport synchrone pour cette garantie transactionnelle.
- Le retour navigateur n’écrit aucun état financier, y compris l’abandon.
  Le suivi `/checkout/shop-confirmation/:orderToken` relit le serveur, affiche
  l’attente, l’échec ou le paiement confirmé, et permet de reprendre une session
  encore payable. Le token est une preuve de possession, dans la base tenant ;
  l’endpoint ne renvoie ni code cadeau ni coordonnées client et interdit le cache.
- Pour un virement, référence et instructions sont affichées. Sans instructions,
  le client est invité à contacter l’établissement avec sa référence. La
  préparation produit est distinguée de l’attente du paiement.

Référence du contrat Stripe : [Checkout Sessions et payment_status](https://docs.stripe.com/api/checkout/sessions).

## Vérifications

Les tests ciblés couvrent notamment :

- commande produit, ancien cadeau, carte monétaire et complément sans booking ;
- aucun état payé après création de session/lecture de confirmation ;
- succès signé, annulation/expiration, échec, signature invalide, doublon,
  événement payé tardif déjà traité et incohérences token/montant/devise ;
- émission unique et e-mail unique de carte monétaire, absence d’émission sur acompte ;
- paiement intégral des cadeaux côté panier, conservation des calculs d’acomptes
  prestation et protection des anciens bons.

Commandes à exécuter avec les dépendances disponibles :

```sh
cd backend
php vendor/bin/phpunit -c phpunit.business.xml --filter 'Stripe|GiftCard|GiftVoucher|ServicePaymentTerms|OrderPaymentTerms'
cd ../frontend
npm run test:unit
npm run build
```

Résultats dans l’environnement du ticket :

- Syntaxe PHP de tous les fichiers PHP modifiés/ajoutés : réussie.
- `git diff --check` : réussi.
- Analyse de syntaxe des scripts JavaScript modifiés dans V8 : réussie (sans
  compilation des templates Vue).
- Exécution isolée du store panier dans V8 avec adaptateurs Pinia/API minimaux :
  acompte de 30 %, paiement sur place, cadeau à valeur intégrale et commande
  unique après double clic/nouvelle tentative vérifiés. Ce contrôle ne remplace
  pas la suite Node ni le build Vue.
- PHPUnit : non exécuté, `vendor/bin/phpunit` absent. Installation Composer
  tentée, téléchargement impossible (`Could not resolve host: api.github.com`).
- Tests JavaScript et build Vue : non exécutés, Node/npm absents.
- Aucun parcours navigateur, envoi réel d’e-mail ou paiement Stripe test distant
  n’a été exécuté. Les scénarios distants restent à vérifier avec les clés test
  et un webhook accessible, en retardant volontairement le webhook avant retour,
  puis en renvoyant le même événement et un événement distinct pour la même session.
  Tester aussi une prestation avec acompte et un établissement sans Stripe activé.
