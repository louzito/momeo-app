<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les dimensions de réservation et les verrous transactionnels anti-surréservation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE momeo_booking ADD planning_code VARCHAR(255) DEFAULT NULL, ADD resource_code VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_momeo_booking_planning ON momeo_booking (planning_code)');
        $this->addSql('CREATE INDEX idx_momeo_booking_resource ON momeo_booking (resource_code)');
        $this->addSql('CREATE TABLE momeo_booking_lock (lock_key VARCHAR(255) NOT NULL, PRIMARY KEY(lock_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE momeo_booking_lock');
        $this->addSql('DROP INDEX idx_momeo_booking_planning ON momeo_booking');
        $this->addSql('DROP INDEX idx_momeo_booking_resource ON momeo_booking');
        $this->addSql('ALTER TABLE momeo_booking DROP planning_code, DROP resource_code');
    }
}
