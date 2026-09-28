<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Médiathèque tenant et protection des usages brouillon/publication'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE todatempo_site_media (id VARCHAR(32) NOT NULL, path VARCHAR(255) NOT NULL, thumbnail VARCHAR(255) NOT NULL, width INT NOT NULL, height INT NOT NULL, alt VARCHAR(300) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE todatempo_site_media_usage (page_id VARCHAR(32) NOT NULL, media_id VARCHAR(32) NOT NULL, PRIMARY KEY(page_id, media_id), INDEX idx_site_media_usage_media (media_id), CONSTRAINT fk_site_media_usage_page FOREIGN KEY (page_id) REFERENCES todatempo_site_page (id) ON DELETE CASCADE, CONSTRAINT fk_site_media_usage_media FOREIGN KEY (media_id) REFERENCES todatempo_site_media (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver les images et leurs usages.'); }
}
