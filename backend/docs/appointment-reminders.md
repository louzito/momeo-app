# Rappels de rendez-vous

La commande `todatempo:reminders:schedule` est à exécuter périodiquement pour chaque tenant (par exemple toutes les 5 minutes avec `--window=5`). Elle crée une livraison idempotente puis publie `SendBookingReminder` sur le transport Messenger `async`. Le worker doit être lancé avec le tenant correspondant, conformément aux autres messages asynchrones du projet.

Les valeurs de repli sont fournies par l'environnement :

- `TODATEMPO_REMINDER_EMAIL_HOURS` : délais email séparés par des virgules, `24` par défaut ;
- `TODATEMPO_REMINDER_SMS_HOURS` : délais SMS séparés par des virgules ;
- `TODATEMPO_SMS_ENABLED` : coupe-circuit global du canal SMS, désactivé par défaut.

Un centre peut surcharger ces valeurs dans le JSON du taxon `skybook_config` :

```json
{
  "reminders": {
    "email": { "enabled": true, "hours": [48, 24] },
    "sms": { "enabled": false, "hours": [24] }
  }
}
```

Le SMS nécessite en plus un numéro et le consentement explicite enregistré lors de la réservation. L'implémentation initiale de `SmsProvider` est volontairement désactivée ; un fournisseur réel se branche par injection de dépendances, avec ses secrets fournis uniquement par l'environnement. Les rendez-vous qui ne sont plus confirmés sont ignorés au moment effectif de l'envoi.
