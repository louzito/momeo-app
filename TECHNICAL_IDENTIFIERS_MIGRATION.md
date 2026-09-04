# Migration des identifiants techniques TodaTempo (V1)

Les noms `todatempo.*`, `TODATEMPO_*`, `X-TodaTempo-*` et les codes
`todatempo_*` sont canoniques. Les compatibilités ci-dessous sont temporaires
et prévues pour suppression en V2.

| Canonique | Ancien nom encore lu | Priorité / écriture |
|---|---|---|
| `X-TodaTempo-Tenant` | `X-Skybook-Tenant` | le nouveau gagne ; proxy écrit uniquement le nouveau |
| `TODATEMPO_TENANT` | `SKYBOOK_TENANT` | le nouveau gagne ; scripts écrivent uniquement le nouveau |
| `TODATEMPO_PROXY_*` | `SKYBOOK_PROXY_*` | le nouveau gagne |
| `TODATEMPO_PROVISIONING_SECRET` | `MOMEO_PROVISIONING_SECRET` | fallback de configuration uniquement |
| `X-TodaTempo-Provisioning-Key` | `X-Momeo-Provisioning-Key` | le nouveau gagne |
| `todatempo.*` (localStorage) | `momeo.*`, `skybook.*` | copie puis suppression de l'ancienne clé |
| `todatempo_*` (attributs/taxons/associations) | `momeo_*`, `skybook_*` | nouvelle écriture canonique ; lecture legacy |

Les commandes `todatempo:*` sont canoniques. Les noms `skybook:*` sont gardés
comme alias Symfony pendant la fenêtre de compatibilité.

## Exécution et retour arrière

La migration localStorage et la copie de `skybook_config` vers
`todatempo_config` sont idempotentes : la cible existante n'est jamais
écrasée. Les données historiques ne sont pas supprimées côté Sylius.

Pour revenir en arrière, redéployer la version précédente : les anciennes
ressources persistées sont toujours présentes. Les sessions localStorage déjà
migrées peuvent être recopiées manuellement vers leur ancienne clé si un retour
du front est nécessaire. Avant suppression des adaptateurs en V2, vérifier que
les anciennes variables/en-têtes ne sont plus observés et archiver les taxons
et attributs legacy après sauvegarde de la base.
