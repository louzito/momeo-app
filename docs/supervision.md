# Supervision TodaTempo

## Sondes HTTP

Les sondes ne nécessitent pas d'authentification et ne renvoient ni exception,
ni URL, ni nom de base de données, ni configuration sensible.

| Endpoint | Usage | Succès | Échec |
|---|---|---|---|
| `GET /health/live` | liveness du processus web | `200` | — |
| `GET /health/ready` | readiness de l'application et du registre des tenants | `200` | `503` |
| `GET /health/tenants/{slug}/live` | tenant connu, actif et servi | `200` | `503` |
| `GET /health/tenants/{slug}/ready` | tenant servi, DB joignable et dépendances obligatoires disponibles | `200` | `503` |
| `GET /metrics` | métriques au format Prometheus | `200` | — |

La liveness ne teste jamais une dépendance : un incident de base de données ne
doit pas provoquer une boucle de redémarrage. La readiness tenant exécute en
revanche un `SELECT 1` après avoir sélectionné la base enregistrée pour le
tenant. Elle teste également chaque URL déclarée dans
`TODATEMPO_REQUIRED_DEPENDENCIES` (liste séparée par des virgules, timeout de
deux secondes). Une réponse HTTP hors de l'intervalle 200–399 rend la sonde
indisponible.

Exemple Kubernetes :

```yaml
livenessProbe:
  httpGet: { path: /health/live, port: 8080 }
readinessProbe:
  httpGet: { path: /health/tenants/skyline/ready, port: 8080 }
```

## Logs structurés

En production, Monolog écrit du JSON. Chaque log émis pendant une requête porte
`extra.correlation_id` et, lorsqu'il est résolu, `extra.tenant`. Seul le slug
public est ajouté au contexte ; aucune configuration tenant n'est journalisée.
Le client peut fournir `X-Correlation-ID` (8 à 128 caractères sûrs). Une valeur
absente ou invalide est remplacée, et l'identifiant retenu est toujours renvoyé
dans l'en-tête de réponse du même nom.

## Métriques

`/metrics` expose des compteurs préfixés par `todatempo_`, avec le seul
label `tenant` : démarrages/arrêts de workers, messages worker échoués, webhooks
Stripe reçus ou rejetés et réservations mises en échec. Le fichier compteur
`var/observability/metrics.json` est mis à jour sous verrou. Dans une
installation à plusieurs pods, chaque pod doit être collecté séparément (les
compteurs ne sont pas un stockage distribué).

Restreindre idéalement `/metrics` au réseau du collecteur au niveau de
l'ingress/proxy. Les sondes JSON et les métriques ne contiennent aucun secret.
