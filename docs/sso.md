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

## Accès professionnel exclusivement depuis le website (ticket #59)

Le frontend ne propose plus de formulaire de mot de passe. Configurer
`VITE_WEBSITE_URL` avec l’URL publique du website (défaut `https://todatempo.fr`).
Le website authentifie la personne, présente **tous** ses espaces actifs puis
propose « Ouvrir mon espace » pour celui sélectionné. Les endpoints de tickets
et le handoff existants restent inchangés. L’API de connexion par mot de passe
`/api/v2/admin/administrators/token` est refusée, même avec un JWT valide.
L’administration Sylius historique et la connexion des clients boutique sont
indépendantes de ce parcours professionnel Vue.

### Contrat de synchronisation à implémenter dans le dépôt website

Le code du website n’est pas présent dans ce dépôt. Le contrat ci-dessous est
une nouvelle intégration ; il ne présume pas qu’un endpoint existe déjà.
Configurer `TODATEMPO_WEBSITE_MEMBERSHIP_URL` côté backend avec son URL serveur
complète (HTTPS en production), et partager `TODATEMPO_PROVISIONING_SECRET`.
L’application envoie un `PUT` avec `X-TodaTempo-Provisioning-Key` et ce JSON :

```json
{
  "slug": "boutique-a",
  "email": "personne@example.com",
  "firstName": "Camille",
  "lastName": "Martin",
  "role": "practitioner",
  "active": true
}
```

Le website doit :

- Authentifier l’appel serveur et valider le tenant et le rôle (`owner`,
  `manager`, `reception`, `practitioner`).
- Retrouver ou créer le compte par email normalisé, sans remplacer le mot de
  passe, le profil ou les adhésions d’un compte existant.
- Créer ou actualiser une adhésion unique `(compte, slug)` contenant `role` et
  `active` ; le rôle appartient à cette adhésion, jamais au compte global.
- Fournir au nouveau compte son parcours habituel d’activation/définition du
  mot de passe, sans mot de passe transmis par l’application métier.
- Répondre 200, 201 ou 204 seulement après enregistrement durable ; répéter le
  même PUT ne doit créer ni doublon ni nouvelle invitation.
- Autoriser l’ouverture d’un espace seulement si l’utilisateur connecté possède
  une adhésion active à cet espace, en conservant les contrôles d’abonnement
  applicables à l’établissement. Ne jamais accepter un email arbitraire du navigateur.

Une personne peut ainsi être praticienne dans A et manager dans B. Chaque base
locale conserve son propre administrateur et son rôle. Le JWT est émis avec le
rôle local, et non un rôle envoyé par le navigateur. Archiver/désactiver un membre
transmet `active: false` pour ce seul espace et interdit aussi localement les
nouveaux tickets, les échanges SSO et l’usage d’un JWT existant.

L’email du membre est obligatoire. L’email de connexion facultatif permet de
conserver une adresse de compte distincte de l’adresse de contact. Une fois lié,
un compte ne peut pas être remplacé depuis cette fiche ; la même adresse ne peut
pas être liée à deux membres d’un même établissement.

Sans configuration ou sans confirmation du website, l’enregistrement échoue
explicitement et les changements locaux ne sont pas validés. La synchronisation
est synchrone, sans transaction distribuée : si le website réussit mais que le
flush local échoue, réessayer le même enregistrement réconcilie les deux côtés.
Un compte distant seul n’autorise aucun SSO sans administrateur local actif.
Les membres existants doivent être enregistrés à nouveau pour synchroniser leur
adhésion ; aucune migration des comptes website n’est exécutée par ce dépôt.

Vérifications d’intégration à effectuer avec le website raccordé : création d’un
nouvel email et activation, ajout du même email dans deux boutiques avec deux
rôles, sélection de chacune, modification de rôle, archivage d’une seule adhésion,
refus d’un espace non autorisé, panne puis nouvelle tentative sans doublon.
