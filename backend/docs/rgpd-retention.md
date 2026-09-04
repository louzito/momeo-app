# Socle RGPD : export, effacement et rétention

Toutes les opérations s'exécutent dans la base du centre résolue par `TenantContext`. La purge CLI refuse volontairement le tenant par défaut implicite : lancer par exemple `TODATEMPO_TENANT=centre-a bin/console todatempo:gdpr:purge --dry-run`, puis sans `--dry-run` après contrôle.

Les exports client et centre sont des JSON privés (`no-store`) et excluent mots de passe et jetons techniques. L'effacement retire le profil enrichi, la liste d'attente et les données libres/sensibles, désactive le compte, pseudonymise réservations, bons cadeaux, client et adresses de commande. Les montants, lignes de commande, références et factures/PDF ne sont pas supprimés : ils constituent la trace comptable. Le journal RGPD local au tenant ne conserve qu'un hash salé par le slug, l'acteur, les compteurs et le motif, jamais l'email en clair.

La purge est idempotente : elle ne reprend pas les réservations déjà pseudonymisées et supprime les listes d'attente arrivées à échéance. Chaque exécution effective est transactionnelle et auditée ; `--dry-run` n'écrit rien.

## Validation juridique requise avant production

- Confirmer par pays et activité les valeurs `TODATEMPO_GDPR_BOOKING_RETENTION_MONTHS` (36), `TODATEMPO_GDPR_WAITLIST_RETENTION_DAYS` (90) et `TODATEMPO_GDPR_INVOICE_RETENTION_YEARS` (10). Cette dernière documente la conservation comptable ; les factures ne sont jamais purgées automatiquement dans ce socle.
- Confirmer si certaines données de rendez-vous/contre-indications relèvent de données de santé et nécessitent une durée, une base légale, un hébergement ou un accès spécifiques.
- Valider les informations minimales devant rester sur la facture archivée, les éventuels gels légaux/contentieux et le délai de conservation du journal d'audit.
- Définir le processus de vérification d'identité, d'approbation et de remise sécurisée de l'export. Les routes fournissent le mécanisme technique, pas cette procédure organisationnelle.
- Vérifier les sauvegardes, réplicas, outils de paiement/email et leurs délais d'effacement : ils sont hors de la transaction de la base applicative.
