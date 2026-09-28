# Mon compte : rendez-vous, commandes et cartes cadeaux (#123)

L’espace `/account` réutilise la connexion client Sylius et regroupe les rendez-vous, les commandes (dont les achats de cartes), les cartes reçues et les cartes achetées. Les nouvelles cartes ne nécessitent aucune connexion bénéficiaire supplémentaire.

## Rattachement et confidentialité

- `GET /api/v2/shop/account/gift-cards` nécessite un JWT client et retourne les cartes rattachées au client courant ainsi que ses achats de cartes émises.
- `POST /api/v2/shop/account/gift-cards/claim` reçoit uniquement le code secret de 128 bits dans le corps JSON. Le client vient de l’identité authentifiée, jamais d’un e-mail fourni. Le rattachement est persisté sous verrou de ligne et idempotent pour ce compte ; un autre compte ne peut pas le remplacer.
- Les deux opérations sont limitées à l’établissement courant ; le JWT reste soumis à l’isolation des établissements existante. Les réponses sont privées et non mises en cache.
- Le bénéficiaire voit le solde disponible, le montant réservé, l’expiration et les 50 dernières opérations. L’historique ne contient aucune référence de commande, identité tierce ou référence de remboursement.
- L’acheteur voit le montant initial et la référence de son achat, sans code secret, solde utilisé ou historique du bénéficiaire. La carte reste accessible via l’e-mail d’émission existant ; un acheteur qui est aussi bénéficiaire peut la rattacher avec son code.
- Aucun rattachement automatique par e-mail ni conversion des anciennes identités n’est effectué. La possession du code reçu est la preuve choisie.

Le solde est relu depuis l’API à l’entrée dans le compte, après rattachement, via « Actualiser » et au retour dans l’onglet depuis le paiement. « Utiliser dans la boutique » ouvre le catalogue : le client y choisit sa prestation ou son produit, puis saisit le code au paiement.

Les anciennes URLs `/beneficiary/...`, l’authentification code + e-mail des bons prestation et leurs réservations sont conservées. La page d’entrée distingue les nouvelles cartes dans Mon compte des anciens bons prestation, accessibles également depuis Mon compte.

## Livraison et vérification

La migration additive `Version20260928070000` ajoute `beneficiary_id`, nullable, avec index et clé étrangère sur le client. Elle doit être appliquée par le déploiement habituel dans chaque base d’établissement après les migrations #118 et #120. Elle ne convertit, ne supprime et ne réinitialise aucune donnée. Elle a été exécutée sur la base de test, pas sur les bases métier en service.

Tests : rattachement et rejeu, refus d’un autre compte, refus d’un autre établissement et d’un e-mail seul, confidentialité acheteur/bénéficiaire, accès HTTP anonyme refusé, relecture du solde après un workflow de paiement réel. Les scénarios Playwright à 360 et 1280 px couvrent l’ajout, le retour au compte, le rafraîchissement du solde, les erreurs récupérables et l’accès aux anciens bons. Les API y sont simulées ; aucun paiement bancaire réel n’est effectué.

## Cause du blocage initial

Le #123 a démarré le 28/09/2026 à 06:26:03 UTC pendant la reprise manuelle du #121 (06:17:13–06:28:11 UTC). Son worktree ne contenait donc pas encore ce prérequis. Le #121 est maintenant intégré dans `main` (`991cb78`). Le worker AutoTicket contrôlait uniquement son verrou de processus ; il suspend désormais la sélection tant qu’un ticket est `running`, y compris une reprise manuelle. Cette correction et ses tests résident dans `/var/www/autoticket`.
