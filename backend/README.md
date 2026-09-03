<p align="center">
    <a href="https://sylius.com" target="_blank">
        <picture>
          <source media="(prefers-color-scheme: dark)" srcset="https://media.sylius.com/sylius-logo-800-dark.png">
          <source media="(prefers-color-scheme: light)" srcset="https://media.sylius.com/sylius-logo-800.png">
          <img alt="Sylius Logo." src="https://media.sylius.com/sylius-logo-800.png">
        </picture>
    </a>
</p>

<h1 align="center">Sylius Standard Edition</h1>

<p align="center">This is Sylius Standard Edition repository for starting new projects.</p>

## About

Sylius is the first decoupled eCommerce framework based on [**Symfony**](http://symfony.com) and [**Doctrine**](http://doctrine-project.org). 
The highest quality of code, strong testing culture, built-in Agile (BDD) workflow and exceptional flexibility make it the best solution for application tailored to your business requirements. 
Enjoy being an eCommerce Developer again!

Powerful REST API allows for easy integrations and creating unique customer experience on any device.

We're using full-stack Behavior-Driven-Development, with [Behat](http://behat.org)

## Documentation

Documentation is available at [docs.sylius.com](http://docs.sylius.com).

## Installation

### Configuration de production

Le backend refuse désormais de démarrer en `APP_ENV=prod` si sa configuration est
incomplète. Partir de `.env.example`, injecter les valeurs par le gestionnaire de
secrets de la plateforme et ne pas versionner de fichier `.env` réel.

Les variables applicatives (`APP_SECRET`, `DATABASE_URL`, `DEFAULT_URI`,
`SKYBOOK_DEFAULT_TENANT`, `MOMEO_PROVISIONING_SECRET`), les trois paramètres JWT,
la clé de chiffrement des paiements et tous les DSN Messenger présents dans
`.env.example` sont obligatoires. Les fichiers désignés par `JWT_SECRET_KEY`,
`JWT_PUBLIC_KEY` et `SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH` doivent être lisibles et
non vides.

Créer `config/tenants.json` depuis `config/tenants.example.json`. Le registre doit
être un objet JSON non vide, chaque entrée doit avoir un `db`, et
`SKYBOOK_DEFAULT_TENANT` doit correspondre à l'une de ses entrées. Les exemples ne
contiennent que des valeurs factices : elles doivent impérativement être remplacées
avant une mise en production.

### Traditional
```bash
$ wget http://getcomposer.org/composer.phar
$ php composer.phar create-project sylius/sylius-standard project
$ cd project
$ yarn install
$ yarn build
$ php bin/console sylius:install
$ symfony serve
$ open http://localhost:8000/
```

For more detailed instruction about traditional way of running Sylius please visit [installation chapter in our docs](https://docs.sylius.com/the-book/sylius-ce-installation).

### Docker

You can run Sylius and all associated infrastructure dependencies (PHP, Nginx, MySQL, Node) on your machine using only Docker containers. Make sure you have installed [Docker](https://docs.docker.com/get-docker/) on your local machine.

**Option 1: Get the latest release**
```bash
LATEST=$(curl -s https://api.github.com/repos/Sylius/Sylius-Standard/releases/latest | grep '"tag_name"' | cut -d'"' -f4)
curl -L -o sylius-latest.zip https://github.com/Sylius/Sylius-Standard/archive/refs/tags/$LATEST.zip
unzip sylius-latest.zip
cd Sylius-Standard-*
```

**Option 2: List available versions**
```bash
curl -s https://api.github.com/repos/Sylius/Sylius-Standard/releases | grep '"tag_name"' | cut -d'"' -f4 | head -10
```

**Option 3: Get a specific version**
```bash
VERSION="v2.x.x"  # Replace with desired version
curl -L -o sylius-$VERSION.zip https://github.com/Sylius/Sylius-Standard/archive/refs/tags/$VERSION.zip
unzip sylius-$VERSION.zip
cd Sylius-Standard-*
```

**Initialize the project (required for all options):**
```bash
make init
```

For more detailed instruction about Docker way of running Sylius please visit [Docker installation chapter in our docs](https://docs.sylius.com/getting-started-with-sylius/sylius-ce-installation-with-docker).

## Troubleshooting

If something goes wrong, errors & exceptions are logged at the application level:

```bash
$ tail -f var/log/prod.log
$ tail -f var/log/dev.log
```

## Contributing

Would like to help us and build the most developer-friendly eCommerce framework? Start from reading our [Contribution Guide](https://docs.sylius.com/en/latest/contributing/)!

## Stay Updated

If you want to keep up with the updates, [follow the official Sylius account on Twitter](http://twitter.com/Sylius) and [like us on Facebook](https://www.facebook.com/SyliusEcommerce/).

## Bug Tracking

If you want to report a bug or suggest an idea, please use [GitHub issues](https://github.com/Sylius/Sylius/issues).

## Community Support

Get Sylius support on [Slack](https://sylius.com/slack), [Forum](https://forum.sylius.com/) or [Stack Overflow](https://stackoverflow.com/questions/tagged/sylius).

## MIT License

Sylius is completely free and released under the [MIT License](https://github.com/Sylius/Sylius/blob/master/LICENSE).

## Authors

Sylius was originally created by [Paweł Jędrzejewski](http://pjedrzejewski.com).
See the list of [contributors from our awesome community](https://github.com/Sylius/Sylius/contributors).
