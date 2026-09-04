# Smoke test V1 de bout en bout

`tests/Smoke/V1EndToEndSmokeTest.php` fait rejouer, dans une seule requête HTTP
de test (`WebTestCase`), le parcours métier complet de la V1 : initialisation
du tenant, connexion admin, création d'une prestation/d'un collaborateur/d'un
planning, inscription et connexion d'un client, réservation d'un créneau,
paiement carte, réception de la facture, déplacement puis annulation du
rendez-vous.

## Ce que le test exécute réellement

Chaque étape métier traverse la même API que les fronts réels — aucune donnée
métier n'est écrite directement en base :

1. **Tenant initialisé** : appelle le service `MinimalSyliusInitializer` (celui
   qu'utilise la commande `todatempo:tenant:initialize`) pour créer un
   administrateur dédié à l'exécution.
2. **Admin connecté** : `POST /api/v2/admin/administrators/token`.
3. **Prestation créée** : `POST /api/v2/admin/products`,
   `POST /api/v2/admin/product-variants`, puis pose de l'attribut
   `todatempo_duration` — exactement la séquence utilisée par l'administration
   front (`frontend/src/api/adminApi.js`).
4. **Collaborateur créé** : `POST /api/v2/admin/staff-members`.
5. **Planning créé** : `POST /api/v2/admin/plannings`.
6. **Client inscrit** : `POST /api/v2/shop/customers` puis
   `POST /api/v2/shop/customers/token`.
7. **Créneau réservé** : lecture de `GET /api/v2/shop/availability`, panier
   Sylius (`/shop/orders`, `/items`, adresse, moyen de paiement, finalisation),
   puis `POST /api/v2/shop/bookings`.
8. **Paiement test** : simulation d'un webhook Stripe `checkout.session.completed`
   signé avec la clé configurée sur le moyen de paiement du tenant (même
   algorithme HMAC que `Stripe\Webhook::constructEvent`), envoyé à
   `POST /api/v2/shop/payments/stripe/webhook/demo`. Aucun appel réseau vers
   Stripe n'est nécessaire.
9. **Email intercepté** : les emails transactionnels passent par Messenger ;
   en environnement de test ce transport est `in-memory://`
   (`config/packages/messenger.yaml`), donc le test lit directement les
   messages envoyés (`messenger.transport.async`) sans dépendre d'un serveur
   SMTP.
10. **Facture générée** : `GET /api/v2/admin/invoices` puis
    `GET /api/v2/admin/invoices/{id}/download`, avec vérification que le
    contenu commence par `%PDF`.
11. **Déplacement** : `POST /api/v2/admin/bookings/{id}/reschedule`.
12. **Annulation** : `POST /api/v2/admin/bookings/{id}/cancel`.

Seuls le nom d'hôte du canal Sylius du tenant et la clé de webhook Stripe sont
posés directement via Doctrine dans `setUp()` : il s'agit de configuration
d'environnement (ce qu'un déploiement réel règle une fois en administration),
pas de données métier du scénario.

## Diagnostic

Chaque appel HTTP est vérifié avec un message d'assertion nommant l'étape
métier concernée (« Étape « paiement test » : ... ») et reproduisant le corps
de la réponse fautive, pour localiser immédiatement l'étape en échec sans
avoir à rejouer le scénario avec un débogueur.

## Nettoyage

Le test génère des identifiants uniques à chaque exécution (`runId`
aléatoire : email admin, email client, code de prestation, code de planning).
`tearDown()` supprime tout ce qu'il a créé (réservation, facture, commande et
client — par cascade Doctrine —, prestation, planning, collaborateur,
administrateur, événement webhook Stripe, fichier PDF de la facture) puis
restaure le nom d'hôte du canal, l'exigence de vérification de compte et la
configuration du moyen de paiement Stripe à leur état d'origine. Un échec de
nettoyage fait échouer le test plutôt que de laisser des données orphelines
silencieuses dans le tenant `demo`.

## Exécution

Le test fait partie de la suite métier isolée, avec une base MySQL dédiée et
des transports synchrones :

```bash
cd backend
make test-business
```

Ou, une fois cette suite déjà installée (schéma migré, clés JWT générées) :

```bash
vendor/bin/phpunit --configuration phpunit.business.xml --filter V1EndToEndSmokeTest --testdox
```

Prérequis d'environnement (déjà nécessaires au reste de la suite métier, voir
`docs/invoices.md`) : le binaire `wkhtmltopdf` doit être installé et
accessible pour que la génération de facture PDF fonctionne — sans lui,
l'étape « facture générée » échoue avec un message explicite plutôt qu'une
erreur HTTP muette.

Le test est intégré à `phpunit.business.xml` et s'exécute donc dans les mêmes
pipelines CI que le reste de la suite métier (`ci.yaml` /
`SyliusLabs/BuildTestAppAction`), sans étape supplémentaire à ajouter.
