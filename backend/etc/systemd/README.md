# Workers Messenger par tenant

L'unite template impose le tenant a partir du nom d'instance. Par exemple,
apres installation de `todatempo-messenger@.service` :

```bash
sudo systemctl enable --now todatempo-messenger@skyline.service
```

Le fichier `/etc/todatempo/backend.env`, lisible seulement par l'utilisateur du
service, fournit les secrets applicatifs. L'unite d'exemple ne contient aucun
secret. `config/tenants.json` doit enregistrer `skyline` et sa base avant le
demarrage ; sinon le worker s'arrete avant toute connexion Doctrine avec un
message indiquant comment corriger le registre.

Diagnostic reproductible (il compare `SELECT DATABASE()` a la base du registre) :

```bash
APP_ENV=prod bin/console skybook:tenant:database-diagnose skyline
```

Le demarrage manuel suit la meme regle :

```bash
SKYBOOK_TENANT=skyline APP_ENV=prod bin/console messenger:consume async --no-interaction
```
