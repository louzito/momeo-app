<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les absences et indisponibilités des collaborateurs Momeo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE momeo_staff_time_off (id INT AUTO_INCREMENT NOT NULL, staff_member_id INT NOT NULL, starts_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', ends_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', reason VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_momeo_time_off_start (starts_at), INDEX idx_momeo_time_off_staff (staff_member_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE momeo_staff_time_off ADD CONSTRAINT FK_MOMEO_TIME_OFF_STAFF FOREIGN KEY (staff_member_id) REFERENCES momeo_staff_member (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE momeo_staff_time_off');
    }
}
