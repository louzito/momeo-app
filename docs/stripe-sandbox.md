# Paiement carte Stripe — sandbox

TodaTempo utilise Stripe Checkout en mode test. Une réservation carte est créée avec les statuts `awaiting_payment` / `awaiting_payment`; elle bloque temporairement le créneau mais n'est confirmée qu'après réception d'un webhook Stripe signé. Le webhook de succès applique la transition Sylius `complete`, passe le paiement à `paid` et la réservation à `confirmed`. Un abandon ou une expiration la passe à `cancelled` et libère le créneau.

## Configuration d'un centre

1. Dans **Moyens de paiement**, ouvrir `stripe_web_elements`.
2. Renseigner les clés Stripe de **test** (`pk_test_…`, `sk_test_…`) et laisser la méthode désactivée pendant la saisie.
3. Créer dans Stripe un endpoint vers `https://<domaine>/api/v2/shop/payments/stripe/webhook/<slug-centre>`.
4. Souscrire à `checkout.session.completed`, `checkout.session.expired` et `checkout.session.async_payment_failed`, puis copier son secret `whsec_…` dans la configuration.
5. Activer la méthode. Les secrets restent dans la configuration chiffrée Sylius et dans les variables/volumes d'environnement; ils ne doivent jamais être ajoutés à Git.

En local, Stripe CLI peut transférer les événements :

```bash
stripe listen \
  --events checkout.session.completed,checkout.session.expired,checkout.session.async_payment_failed \
  --forward-to http://localhost:8081/api/v2/shop/payments/stripe/webhook/demo
```

Utiliser le `whsec_…` affiché par la CLI dans la configuration du centre.

## Recette V1

- Succès : carte `4242 4242 4242 4242`, date future, CVC quelconque. Vérifier paiement `paid` et réservation `confirmed`.
- Refus : carte `4000 0000 0000 0002`. Stripe affiche le refus; la réservation ne doit jamais devenir `confirmed`.
- Abandon : depuis Stripe, revenir à la boutique. Vérifier paiement et réservation `cancelled`, puis que le créneau est de nouveau disponible.
- Rejeu : `stripe events resend <event_id> --webhook-endpoint <endpoint_id>`. La seconde réponse contient `replayed: true` et aucun effet métier/email supplémentaire n'est produit.

La confirmation client peut brièvement afficher « confirmation sécurisée en cours » si la redirection précède le webhook; elle interroge alors de nouveau la réservation. Aucun statut `paid_demo` n'est utilisé.
