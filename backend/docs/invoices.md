# Factures PDF

## Stratégie de génération

Les factures utilisent l’adaptateur `knp_snappy` officiellement pris en charge
par `sylius/pdf-generation-bundle`. Il appelle directement `wkhtmltopdf` et ne
nécessite ni Gotenberg, ni service Docker annexe.

Installer le binaire sur chaque environnement qui exécute PHP :

```bash
# Debian / Ubuntu
sudo apt-get update && sudo apt-get install -y wkhtmltopdf

# Vérification
wkhtmltopdf --version
```

Configurer ensuite son chemin absolu dans `.env.local` ou dans les variables du
service PHP :

```dotenv
WKHTMLTOPDF_PATH=/usr/bin/wkhtmltopdf
WKHTMLTOIMAGE_PATH=/usr/bin/wkhtmltoimage
```

Après modification, vider le cache avec `php bin/console cache:clear`. La
transition `complete` du paiement matérialise et archive le PDF avant l’envoi de
l’email de facture. Pour les anciennes commandes :

```bash
php bin/console sylius-invoicing:generate-invoices
```

## Mentions et stockage

Le vendeur, son adresse et ses informations de facturation sont les données du
canal Sylius, modifiables dans l’administration. Elles sont figées dans la
facture à sa création. Les textes d’email associés restent configurables dans
« Configuration boutique > Emails > Facture générée ».

Les PDF ne sont jamais placés sous `public/`. Ils sont archivés dans
`private/invoices/<tenant>/` et le cache de résolution est lui aussi séparé par
tenant. Le téléchargement admin exige un JWT admin portant la claim du tenant.
Le téléchargement client exige un JWT client du même tenant, vérifie l’adresse
du propriétaire de la commande et ne sert que les factures payées. Une absence
ou un défaut d’appartenance renvoie toujours 404 afin de ne pas divulguer les
identifiants existants.

Les répertoires doivent être accessibles en écriture par l’utilisateur PHP :

```bash
mkdir -p private/invoices var/cache
chown -R www-data:www-data private/invoices var/cache
```

## Contrôle après installation

Effectuer un paiement de test, puis vérifier que le PDF commence par `%PDF`,
qu’il existe dans le répertoire privé du tenant et que le même identifiant
appelé avec le JWT d’un autre client ou d’un autre tenant renvoie 401/404.
