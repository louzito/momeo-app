<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Conserver la reprise publiée et versionner les modifications des menus (tenant).'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todatempo_site_page ADD legacy_published JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE todatempo_site_menu ADD draft_version INT NOT NULL DEFAULT 0');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver le contenu historique.'); }
}
