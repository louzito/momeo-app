# Connexion du website à l'application

Le website crée un ticket via un appel serveur authentifié à
`TODATEMPO_PROVISIONING_URL/internal/todatempo/admin-login-tickets`.
`TODATEMPO_PROVISIONING_SECRET` doit correspondre sur les deux applications.
Cette URL peut être interne, par exemple `http://127.0.0.1:8081`.

Le navigateur poste ensuite le ticket et le champ `tenant` au backend public,
configuré **dans le website** par `TODATEMPO_SSO_PUBLIC_URL`. Sur un hébergement
sous préfixe : `https://<hôte>/todatempo-app/backend`. Le secret de provisioning
n'est jamais transmis au navigateur. Le ticket n'apparaît pas dans l'URL.

Le backend accepte le champ `tenant` uniquement sur le POST de handoff SSO.
Un en-tête de tenant reste prioritaire. Le ticket est vérifié dans le cache du
centre sélectionné ; changer le champ ne permet pas d'utiliser le ticket d'un
autre centre. Le cookie temporaire HttpOnly cible le chemin public réel de
l'API (`<base du backend>/api/v2/admin/todatempo/sso/session`), puis est consommé
et supprimé lors de l'échange contre le JWT.

`DEFAULT_URI` du backend désigne la base publique **du frontend, sans tenant**,
par exemple `https://<hôte>/todatempo-app`. Le générateur ajoute le slug pour
rediriger vers `/<slug>/admin/login`. `SKYBOOK_DEFAULT_TENANT` doit désigner un
centre existant pour les services historiques, sans remplacer le centre explicite
du parcours SSO.

## Hébergement protégé par HTTP Basic

HTTP Basic et JWT ne peuvent pas occuper simultanément `Authorization`.
Configurer ces deux valeurs correspondantes uniquement sur cet hébergement :

```dotenv
# Backend : .env.local ou environnement du service
TODATEMPO_JWT_AUTH_HEADER=X-TodaTempo-Authorization
# Frontend : .env.local, intégré au build
VITE_JWT_AUTH_HEADER=X-TodaTempo-Authorization
```

Recompiler le frontend et renouveler le cache backend après modification.
Sans configuration particulière, les deux parties utilisent `Authorization`.
La protection HTTP Basic de l'hébergeur reste active ; les credentials Basic
ne doivent jamais être intégrés au frontend.

## Vérifications

- Les clés JWT existantes et leurs répertoires parents doivent être lisibles et
  traversables par le compte PHP-FPM. Ne pas régénérer les clés pour corriger
  simplement des permissions.
- Les migrations du tenant doivent correspondre au code. La migration
  `Version20260907000000` ajoute notamment `team_role` et `staff_member_id`,
  nécessaires au chargement de l'administrateur. Sauvegarder avant migration.
- Le propriétaire du workspace doit correspondre à un administrateur activé
  dans ce tenant et disposer d'un abonnement autorisant l'accès.
- Le handoff doit répondre 302 vers le bon centre, l'échange de session 200,
  puis `/api/v2/admin/team/session` doit répondre 200 avec le JWT et le tenant.
  Sans JWT, ce dernier endpoint doit répondre 401.
