<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Disable shop account email verification: the customer front registers then signs in immediately'; }

    public function up(Schema $schema): void
    {
        // Le front client inscrit puis connecte dans la foulee (aucun parcours de
        // verification d'email n'existe cote Vue) : tant que le canal exige la
        // verification, Sylius cree un compte desactive et la connexion qui suit
        // echoue en « Invalid credentials ». MinimalSyliusInitializer pose la meme
        // valeur pour les centres crees apres cette migration.
        $this->addSql('UPDATE sylius_channel SET account_verification_required = 0 WHERE account_verification_required = 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE sylius_channel SET account_verification_required = 1 WHERE account_verification_required = 0');
    }
}
