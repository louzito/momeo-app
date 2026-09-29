# Panier commun — #124

Le lien **Panier** rassemble une prestation (et ses options), plusieurs produits
avec quantités et jusqu’à dix cartes cadeaux. Les pages produits et cartes ajoutent
au même panier ; le choix de créneau et les informations propres à la prestation
restent dans le parcours existant. Coordonnées, récapitulatif et paiement sont
communs. Le panier et sa référence de reprise sont conservés dans sessionStorage,
par établissement et par onglet, jusqu’au nouvel achat/effacement de la session.

Une commande accepte une seule réservation. La remise est commune aux produits :
retrait ou livraison, uniquement si tous les produits autorisent ce mode. Les
incompatibilités sont expliquées avant paiement. Les frais de livraison sont le
maximum des frais des produits, comme dans le checkout physique précédent. Le
processeur de frais Sylius est décoré uniquement pour ces nouvelles commandes afin
de ne pas facturer également le tarif générique du transporteur. Les anciennes
commandes conservent leur traitement.

## Engagement atomique

`POST /api/v2/shop/checkout` exige un établissement explicite (en-tête canonique,
compatibilité SkyBook ou domaine vérifié). Payload : `key` aléatoire 128 bits,
`items: [{code, quantity}]`, `gifts`, `customer`, `slot` si prestation, `mode`,
`paymentMethod`, `giftCardCode` facultatif. Les noms/prix du navigateur ne sont
jamais utilisés pour facturer. Les variantes/prix et modes sont relus dans la base
du tenant. Le stock est verrouillé avant les workflows Sylius.

Une transaction englobe création Sylius, stock, modalités de paiement, réservation,
ressource et crédit cadeau. Le verrou du canal sérialise les nouveaux paniers de
l’établissement (choix simple pour cette V1), puis les variantes sont verrouillées
dans l’ordre des codes. La recherche de reprise utilise une lecture courante
InnoDB : deux requêtes concurrentes avec la même clé retournent la même commande.
L’empreinte de requête interdit de réutiliser une clé avec un autre contenu. En cas
de réponse perdue, le navigateur conserve le payload exact et le renvoie. Un refus
explicite 409/422 permet de corriger le panier ; une erreur réseau conserve la clé.

Les services existants gardent les contrôles de délai, collaborateur, créneau et
ressource. Le POST historique de réservation retrouve une réservation déjà liée
à la commande plutôt que de la créer deux fois. Les bons et URLs historiques,
commandes et accès compte client restent disponibles.

## Paiement et échec

L’acompte s’applique uniquement à la prestation et ses options. Produits, livraison
et cartes offertes sont payables intégralement. L’instantané conserve le total de
la prestation, son acompte et le solde sur place indépendamment du total à payer.
Le crédit cadeau est borné au total dû **moins les cartes achetées**, dans la
préparation et dans le service transactionnel de réservation du crédit.

Le complément utilise Stripe ; une carte achetée reste obligatoirement financée
par ce complément (ou un virement sans crédit cadeau). L’émission attend le paiement
complet de la commande. Chaque carte achetée dispose d’une ligne d’émission unique,
de son destinataire et de son document ; les anciennes émissions utilisent la
ligne 0. Les événements répétés n’émettent/débitent/vendent pas deux fois.

L’échec/cancel du paiement annule la commande mixte via le workflow Sylius et libère
stock, créneau, ressource et crédit. Un garde de réentrance évite le double retour
de stock quand l’annulation bancaire entraîne celle du paiement cadeau. Le cron
existant `app:gift-card-payments:expire`, à exécuter pour chaque tenant, couvre aussi
les paiements Stripe de paniers communs sans crédit qui n’ont pas démarré après une
heure. Après démarrage Stripe, la libération attend le webhook signé, afin de ne
pas restituer un crédit qui vient d’être encaissé. Les virements restent dans le
suivi habituel de l’établissement. Aucun appel Stripe n’est fait dans la transaction
de création ; la confirmation commune permet de reprendre le paiement.

## Livraison et vérification

Migration additive **Version20260929090000** : clé/contexte de panier dans la
commande et unicité commande + ligne pour les cartes. À appliquer par le déploiement
habituel à toutes les bases tenant avant de reconstruire le cache. Aucun reset de
données, push ou déploiement effectué par ce ticket. La migration a été exécutée
uniquement sur `todatempo_test` pendant la validation.

Validation : tests métier sur InnoDB réel (panier mixte et acompte, stock/remise,
options historiques, paiement par crédit total/partiel, complément et émission,
échec/expiration, rejouage et deux processus concurrents), suites existantes
Commerce/GiftCard/Payment/Booking/Site et intégrations webhook/créneau, tests
unitaires frontend, Playwright à 360/1280 px et anciens parcours de réservation,
build et contrôle du bundle de production, mapping Doctrine et lint du conteneur.
Les tests navigateur simulent l’API ; aucun paiement bancaire réel n’a été effectué.
Les tests de dépendances émettent des dépréciations sans échec.
