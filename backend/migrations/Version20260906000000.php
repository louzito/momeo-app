<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add persistent professional client profiles and consent audit'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE todatempo_client_profile (id INT AUTO_INCREMENT NOT NULL, booking_email VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(40) DEFAULT NULL, visible_notes LONGTEXT DEFAULT NULL, internal_notes LONGTEXT DEFAULT NULL, tags JSON NOT NULL COMMENT '(DC2Type:json)', allergies LONGTEXT DEFAULT NULL, contraindications LONGTEXT DEFAULT NULL, consents JSON NOT NULL COMMENT '(DC2Type:json)', consent_history JSON NOT NULL COMMENT '(DC2Type:json)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_todatempo_client_profile_booking_email (booking_email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE todatempo_client_profile');
    }
}
