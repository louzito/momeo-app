# Indicateurs du tableau de bord administrateur

La route tenant-aware `GET /api/v2/admin/dashboard/overview` accepte `from` (inclus), `to` (exclu) et `timezone` (identifiant IANA, par exemple `Europe/Paris`). Sans paramètres, elle couvre la journée courante dans le fuseau configuré pour l'établissement. Une plage est limitée à 366 jours.

- **Rendez-vous** : rendez-vous dont le début appartient à la plage, hors annulations et absences (`no_show`).
- **Chiffre d'affaires encaissé** : total TTC des rendez-vous de la plage ayant `payment_state=paid`, plus les cartes cadeaux activées (donc encaissées) pendant la plage. Les montants API sont en centimes.
- **Taux d'occupation** : minutes des rendez-vous bloquants de la plage divisées par les minutes d'ouverture des plannings actifs, pondérées par leur capacité. Une capacité inexistante produit un taux de 0 %. Le résultat est plafonné à 100 %.
- **Annulations / absences** : rendez-vous de la plage ayant respectivement le statut `cancelled` ou `no_show`.
- **Nouveaux clients** : adresses email dont la toute première réservation a été créée pendant la plage (comparaison insensible à la casse).
- **Cartes cadeaux** : `sold` compte les cartes créées pendant la plage ; `paidAmount` additionne celles activées pendant la plage. La ventilation d'état est l'état courant de toutes les cartes du tenant.

Les bornes sont converties en UTC avant les comparaisons. Les ouvertures restent interprétées dans le fuseau propre à chaque planning, y compris lors des changements d'heure.

Les plannings datés contribuent uniquement aux dates explicitement renseignées ; les plannings hebdomadaires contribuent selon leurs jours récurrents. Pour les créneaux datés, la durée conventionnelle est d’une heure, comme lors de leur normalisation. Seuls les créneaux de la plage sélectionnée sont comptés : les 88 créneaux d’octobre ne donnent aucune capacité un jour sans ouverture ou sur une plage hors octobre. Sans rendez-vous, le taux reste à 0 % même si la capacité est positive.
