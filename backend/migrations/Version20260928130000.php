<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260928130000 extends AbstractMigration
{
    public function getDescription(): string { return 'Destination brouillon et publiée du bouton principal du site (tenant).'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todatempo_site_page ADD previous_slugs JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE todatempo_site_menu ADD primary_link JSON DEFAULT NULL, ADD published_primary_link JSON DEFAULT NULL');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver les destinations publiées.'); }
}
