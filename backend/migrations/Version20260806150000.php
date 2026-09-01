<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les collaborateurs Momeo, leurs prestations et leurs horaires de travail.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE momeo_staff_member (id INT AUTO_INCREMENT NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, job_title VARCHAR(120) DEFAULT NULL, bio LONGTEXT DEFAULT NULL, color VARCHAR(7) NOT NULL, active TINYINT(1) NOT NULL, bookable TINYINT(1) NOT NULL, service_codes JSON NOT NULL, working_hours JSON NOT NULL, position INT NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_momeo_staff_active (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE momeo_staff_member');
    }
}
