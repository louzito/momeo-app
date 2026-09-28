<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927223000 extends AbstractMigration
{
    public function getDescription(): string { return 'Coordonnées et choix de remise des achats de cartes cadeaux'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_order ADD gift_card_purchase JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les informations des cadeaux vendus doivent être conservées.');
    }
}
