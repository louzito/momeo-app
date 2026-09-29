# Éditeur de sections — #129

Depuis **Mon site internet → Pages → Modifier les sections**, composer un brouillon
avec bannière, texte riche, image et texte, galerie, FAQ et informations pratiques.
Les images et liens utilisent les bibliothèques du tenant courant. Les anciennes
sections image/titre/bouton restent lisibles et modifiables.

Actions : ajout, sélection, déplacement par glisser-déposer ou boutons clavier,
duplication, masquage, suppression. Variantes limitées aux couleurs du thème,
alignement et position d’image. L’aperçu 360 px/ordinateur emploie `SiteSections`,
composant destiné au rendu public avec son dictionnaire de médias et liens résolus.
Son conteneur doit définir `container-type:inline-size; container-name:site-page`.
La bascule des routes publiques appartient au ticket #132.

Tiptap 3.31.3, compatible Vue 3, est verrouillé dans package-lock.json, chargé dans
le seul écran d’édition. Source : https://tiptap.dev/docs/editor/getting-started/install/vue3
Le schéma conserve paragraphes, titres 2/3, gras, listes et liens HTTP(S) ; les
anciens italiques restent supportés. Le collage passe par le schéma Tiptap. Aucun
HTML libre n’est enregistré ni rendu avec v-html. Le serveur revalide toutes les
structures, tailles, URLs et références de médias/pages dans la base du tenant.
Les listes imbriquées ne sont pas acceptées par cette première version.

PUT /admin/site/pages/{id} exige maintenant `revision`, à côté du document existant.
Un client obsolète reçoit 422 sans écriture ; une édition périmée reçoit 409. La
version Doctrine couvre aussi une écriture concurrente après lecture. Les deux
écrans existants de renommage/images envoient la révision. En cas d’échec/conflit,
le brouillon reste dans l’écran ; quitter/recharger demande d’abord confirmation.
La sauvegarde n’altère pas la version publiée. L’API publique filtre les sections
masquées et leurs médias ; une page seulement en brouillon reste introuvable.

Vérification : tests PHPUnit Site (validation, isolation, publication, conflit),
tests unitaires frontend, build Vue et Playwright à 390/1280 px. Les tests navigateur
simulent les réponses de l’API ; les règles de persistence sont testées avec SQLite
isolé. Aucune migration nécessaire ni modification des bases métier.
