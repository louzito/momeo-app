<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add limited bookable resources, calendars and service requirements'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE todatempo_bookable_resource (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, capacity INT NOT NULL, calendar JSON NOT NULL COMMENT '(DC2Type:json)', active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_todatempo_resource_code (code), INDEX idx_todatempo_resource_active (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE sylius_product ADD bookable_resource_codes JSON DEFAULT NULL COMMENT '(DC2Type:json)', ADD bookable_resource_required TINYINT(1) DEFAULT 0 NOT NULL");
        $this->addSql('UPDATE sylius_product SET bookable_resource_codes = JSON_ARRAY() WHERE bookable_resource_codes IS NULL');
        $this->addSql("ALTER TABLE sylius_product MODIFY bookable_resource_codes JSON NOT NULL COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_product DROP bookable_resource_codes, DROP bookable_resource_required');
        $this->addSql('DROP TABLE todatempo_bookable_resource');
    }
}
