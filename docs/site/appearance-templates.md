# Modèles et apparence — #131

Les prérequis #129 et #130 sont intégrés dans `main` (historique, documentation,
composants et tests vérifiés avant modification). Aucune dépendance ajoutée,
aucune migration, aucune opération de publication ou de déploiement exécutée.

## Création de pages

Depuis Pages, choisir Page vide, Accueil, Présentation de l’établissement ou
Contact. La création ouvre directement l’éditeur des sections. Les modèles sont
construits par `SiteTemplateService` dans la connexion Doctrine du tenant :
coordonnées du document existant et du canal Sylius, textes enregistrés et images
de sa médiathèque. Les champs manquants restent vides. Aucun avis, horaire,
argument commercial ou témoignage n’est inventé. Les images choisies restent
remplaçables dans l’éditeur ; les anciens logos et bannières restent accessibles
via leur configuration historique.

`POST /api/v2/admin/site/pages` accepte `template` optionnel : `blank`, `home`,
`presentation`, `contact`. L’absence conserve le contrat précédent. Seuls les
blocs déjà disponibles sont utilisés. Le document passe par les mêmes contrôles
que la sauvegarde (schéma fermé, médias, liens, catalogue). Les usages des médias
sont enregistrés dans le même flush que la page. La page n’est jamais publiée
par sa création et aucun rôle système n’est imposé par un modèle.

## Une seule configuration d’apparence

`Mon site internet → Apparence` ouvre `/admin/site/appearance`. L’ancienne URL
`/admin/settings?section=appearance` redirige vers cet écran, et son ancien
formulaire a été retiré. Logo, couleurs du haut/pied de page, palettes existantes
et trois choix typographiques sont les seules options de thème. Les liens
sociaux rejoignent les coordonnées de l’établissement. Les contenus légaux
restent accessibles, les réglages de réservation et paiement restent dans Réglages.
Les écrans avertissent avant de quitter avec des modifications non enregistrées.

Le stockage reste le document du taxon `todatempo_config`, avec compatibilité
`skybook_config`. Les images historiques de l’ancien taxon et l’ancienne couleur
`text` sont reprises. Aucun autre document de branding ni table n’est créé.
`GET/PUT /api/v2/admin/site/appearance` exige la permission `settings` et un tenant
explicite, sans cache partagé. Le PUT valide les couleurs et leur contraste,
les palettes, la typographie, l’appartenance du logo et la destination du bouton.
Un jeton de révision refuse une sauvegarde obsolète (409). La modification du
brouillon et du bouton utilise une transaction Doctrine ; l’instantané publié,
les contenus légaux et les paramètres opérationnels sont conservés.

Le bouton principal reste `SiteMenu.primaryLink` : Apparence est son seul éditeur,
Menus contient un lien vers celui-ci. Enregistrer les entrées du menu sans ce
champ conserve sa destination, afin qu’un ancien écran ouvert ne l’écrase pas.
Une suppression explicite du menu conserve son comportement antérieur.
Les accès aux taxons de configuration et à leurs images sont également réservés
à `settings`, pour que le chemin historique ne contourne pas les permissions.

`siteThemeStyle` produit les mêmes variables CSS pour les pages, le catalogue,
la commande et les aperçus. L’aperçu immédiat les applique à son conteneur seulement,
sans changer le thème global ou publié. Les aperçus de sections relisent le thème
brouillon. La lecture publique continue à prendre exclusivement la configuration
publiée. La publication des pages et menus reste le contrat de l’étape suivante ;
la publication historique des réglages est conservée.

## Vérifications

- 328 tests backend ciblés, 576 assertions : sites, publication, références,
  permissions et isolement du tenant, modèles et thème.
- 22 suites de tests unitaires frontend réussies.
- Build Vite et contrôle du bundle de production réussis.
- Syntaxe PHP des nouveaux fichiers et `git diff --check` réussis.
- Scénarios Playwright ajoutés pour les aperçus mobile/ordinateur, couleurs et
  logo existants, isolation de l’aperçu, clavier, erreurs et brouillon ; scénarios
  navigation et création adaptés. Non exécutés : Playwright n’est pas installé
  dans les dépendances locales disponibles.
- Installation Composer complète impossible hors réseau (archives manquantes).
  Les versions du lockfile disponibles en cache ont permis les tests ciblés.
  `lint:container` ne démarre pas faute de fichier `.env` ; ce contrôle n’est pas
  annoncé validé. Aucune base hébergée n’a été modifiée.
