# Navigation du back-office

Le prérequis AutoTicket #115 est intégré dans l’historique fourni :
`1a4d59e` (navigation publique et retrait du calendrier public).

Le menu est regroupé par usages. Chaque entrée reprend la permission de sa
route ; les groupes sans entrée autorisée sont masqués. Les factures restent
accessibles depuis les commandes. Les écrans de création et de modification
conservent la sélection de leur rubrique. « Voir mon site » reste dans le pied
du menu, en dehors de la liste défilante.

| Groupe | Écrans |
| --- | --- |
| Tableau de bord | Vue d’ensemble |
| Rendez-vous | Agenda, réservations, liste d’attente |
| Clients | Liste des clients |
| Catalogue | Prestations, produits physiques, options et suppléments |
| Ventes | Commandes et factures, cartes et chèques cadeaux |
| Mon site internet | Accueil, apparence (logo, couleurs, réseaux sociaux), conditions générales, mentions légales |
| Réglages | Établissement (identité, coordonnées, règles de réservation), équipe, plannings, ressources, moyens de paiement, commerce, emails |

Le ticket #126 ajoute « Pages » : création, renommage, duplication et archivage
des nouvelles pages. Les éditeurs de sections, de menus et la médiathèque ne
sont pas encore disponibles : aucune entrée fictive n’est affichée. Les images existantes restent
modifiables dans Apparence (logo) et Page d’accueil (bannières).

Les rubriques de configuration utilisent `/admin/settings?section=…` et le même
composant, sans copie du brouillon. Les modifications non enregistrées sont
conservées lors du passage entre ces rubriques et avec précédent/suivant.
L’ancienne URL `/admin/settings` ouvre Établissement ; une section inconnue
retombe sur cette rubrique. L’enregistrement et la publication portent toujours
sur la configuration complète via les API existantes. Les anciennes routes,
permissions serveur et données métier restent inchangées.

Sur mobile, le menu utilise un bouton nommé, ferme avec Échap, retient le focus
pendant son ouverture et le rend au bouton à la fermeture ou au contenu après
navigation. Les groupes sont des boutons natifs avec `aria-expanded` et les
liens sélectionnés utilisent `aria-current`.

## Vérifications du ticket #125

- Tests unitaires frontend, dont résolution des routes réelles, permissions,
  groupes vides et sélection des détails/rubriques : réussis.
- Build Vite : réussi (avertissement préexistant sur les imports du store session).
- Aucun champ de configuration supprimé ou dupliqué lors du déplacement.
- Scénarios Playwright ajoutés dans `frontend/tests/e2e/admin-navigation.spec.js` :
  accès par permission, liens, clavier/mobile, brouillon commun, publication et
  reprise après erreur. Exécution impossible dans cet environnement : écoute
  TCP locale refusée (`listen EPERM`) et lancement Chromium refusé
  (`Operation not permitted`). Ces scénarios ne sont pas annoncés comme validés.
