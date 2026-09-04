# Permissions minimales en production

Cette checklist ne contient aucune valeur de secret. Les secrets sont injectes
par l'orchestrateur et ne doivent jamais etre stockes dans le depot.

## Identite du processus web et des workers

- Executer PHP-FPM, les commandes planifiees et chaque worker Messenger avec un
  utilisateur systeme non privilegie, sans shell interactif.
- Lecture seule sur le code applicatif, `config/tenants.json`, les cles JWT
  publiques/privees et la cle de chiffrement des paiements. Les fichiers de cles
  sont limites a cet utilisateur (mode `0400` ou `0440`).
- Ecriture uniquement sur `var/cache/`, `var/log/`, `var/sessions/`,
  `var/observability/`, `private/invoices/` et `public/media/image/`.
- Aucun droit d'ecriture sur `config/`, `src/`, `templates/`, `vendor/` ou les
  fichiers servis comme assets. Le processus ne doit pas pouvoir modifier ses
  cles JWT.

## Base, stockage et reseau

- Un compte SQL applicatif par tenant, limite a sa base ; pas de droits globaux,
  de creation d'utilisateur, de `FILE`, `SUPER` ou administration du serveur.
- Le compte de migration (DDL) est distinct et absent du runtime normal.
- Les factures restent sous `private/invoices/`, hors racine web. Leur lecture
  passe exclusivement par l'endpoint authentifie qui controle proprietaire,
  paiement et tenant puis repond avec `Cache-Control: private, no-store`.
- Autoriser en sortie uniquement SQL, transports email/SMS/paiement declares et
  dependances de readiness. Aucun port d'administration ne doit etre public.

## HTTP et verification avant ouverture

- `APP_ENV=prod`, `APP_DEBUG=0`, HTTPS seul ; ne jamais exposer profiler/WDT.
- Servir front et API sur la meme origine. L'API n'emet volontairement aucun
  `Access-Control-Allow-Origin`; les formulaires a session gardent leur jeton
  CSRF, tandis que les API JWT stateless utilisent `Authorization: Bearer`.
- Verifier que les uploads admin sont limites a 5 Mio et a JPEG/PNG/WebP, que le
  repertoire media n'interprete aucun script, et que les erreurs 5xx ne montrent
  ni trace, ni chemin, ni message d'exception interne.
- Lancer `php bin/console about --env=prod`, le validateur de configuration et
  les tests securite apres chaque changement de permissions.
