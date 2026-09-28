<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Socle pages et menus du site, sans modification du site historique (base tenant).'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE todatempo_site_page (id VARCHAR(32) NOT NULL, slug VARCHAR(120) NOT NULL, role VARCHAR(20) DEFAULT NULL, draft JSON NOT NULL, published JSON DEFAULT NULL, published_slug VARCHAR(120) DEFAULT NULL, archived TINYINT(1) NOT NULL DEFAULT 0, revision INT NOT NULL DEFAULT 1, PRIMARY KEY(id), UNIQUE INDEX uniq_site_slug (slug), UNIQUE INDEX uniq_site_role (role), UNIQUE INDEX uniq_site_published_slug (published_slug)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE todatempo_site_menu (location VARCHAR(20) NOT NULL, published JSON DEFAULT NULL, revision INT NOT NULL DEFAULT 1, PRIMARY KEY(location)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE todatempo_site_menu_item (id VARCHAR(32) NOT NULL, menu_location VARCHAR(20) NOT NULL, sort_order INT NOT NULL, document JSON NOT NULL, PRIMARY KEY(id), INDEX idx_site_menu (menu_location), CONSTRAINT fk_site_menu FOREIGN KEY (menu_location) REFERENCES todatempo_site_menu (location) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver les documents et les versions publiées.'); }
}
