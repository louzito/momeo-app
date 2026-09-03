# Inventaire de migration d'identité TodaTempo

Cette première passe harmonise les surfaces visibles sans modifier les contrats persistés. Les éléments ci-dessous expliquent les résultats attendus des recherches textuelles résiduelles et devront être traités par une migration dédiée.

## Base de données et modèle métier

- Tables, index et contraintes Doctrine : `momeo_booking`, `momeo_staff_member`, `momeo_staff_time_off`, `skybook_gift_voucher` et leurs index associés. Ils sont référencés par les migrations déjà exécutées et par les attributs ORM.
- Colonnes et accesseurs historiques des chèques cadeaux : `jump_type_code`, `jump_type_name`, `jumpTypeCode`, `jumpTypeName`. Les accesseurs `service*` existent déjà comme façade métier ; supprimer les alias exige une migration coordonnée de la base et des consommateurs.
- Attributs et taxons Sylius : `momeo_duration`, `momeo_capacity`, `momeo_requirements`, `skybook_config`, `skybook_plannings`, `skybook_jumps`, ainsi que les codes `jump_*`. Ces codes identifient des données déjà créées dans chaque base d'établissement.
- Marqueur de commande `SKYBOOK_GIFT`, conservé pour relire les commandes et chèques cadeaux existants.

## API, authentification et exploitation

- Routes internes et noms de routes Symfony contenant `momeo_*` ou `skybook_*`, notamment le SSO `/admin/momeo/sso/*` et le provisioning `/internal/momeo/*`. Des intégrations externes peuvent les appeler.
- En-têtes et secrets `X-Skybook-Tenant`, `X-Momeo-Provisioning-Key`, `SKYBOOK_*`, `MOMEO_PROVISIONING_SECRET`, cookies `MOMEO_ADMIN_SSO` et clés de cache/session `momeo.*` / `skybook.*`. Leur rotation doit prévoir une période de double lecture.
- Commandes console `skybook:*`, noms de scripts `skybook-provision.mjs` / `skybook-ops.mjs`, configuration `skybook_mailer.yaml` et paramètres de services `skybook.*`. Ils font partie des procédures d'exploitation actuelles.
- Noms de bases `skybook_template`, `skybook_pool_*`, registre des tenants et fichiers Caddy générés. Leur renommage implique sauvegarde, bascule de connexions et régénération des proxys.

## Frontend et compatibilité

- Le modèle interne conserve provisoirement `jumpType`, `jumpTypeId`, `jumpTypes`, `jumper`, `boardingPass` et `PER_JUMP`, ainsi que la route `/boarding-pass`. Ces clés circulent dans les stores, mocks et réponses API ; les renommer sans versionner le contrat casserait les sessions et les parcours existants.
- Les identifiants de démonstration historiques (`dz_*`, certains slugs et codes `opt_sky_*`) sont des clés de relation, pas des textes affichés. Ils doivent être migrés atomiquement avec toutes leurs références.
- `AdminOrders.vue` reconnaît encore le préfixe textuel `SkyBook —` uniquement pour nettoyer les notes de commandes historiques avant affichage. Les nouvelles notes sont émises avec le préfixe TodaTempo et le vocabulaire prestation/client.
- La fonction Twig et la classe `skybook_email_text` / `SkybookEmailExtension` restent nommées ainsi car tous les gabarits email et la configuration de services les référencent. Les contenus rendus et valeurs de repli sont désormais TodaTempo.

## Séquence recommandée pour le ticket suivant

1. Ajouter les nouveaux identifiants TodaTempo en double lecture/écriture.
2. Migrer chaque base d'établissement et les sessions actives, avec vérification des volumes et possibilité de retour arrière.
3. Basculer API, proxy, scripts et intégrations vers les nouveaux contrats.
4. Retirer les alias historiques, puis vérifier par recherche que seules les anciennes migrations immuables les contiennent.
