<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les réservations Momeo persistantes et leur lien avec les collaborateurs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE momeo_booking (id INT AUTO_INCREMENT NOT NULL, staff_member_id INT DEFAULT NULL, reference VARCHAR(20) NOT NULL, public_token VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, service_code VARCHAR(255) NOT NULL, service_name VARCHAR(255) NOT NULL, staff_name VARCHAR(255) DEFAULT NULL, customer_first_name VARCHAR(100) NOT NULL, customer_last_name VARCHAR(100) NOT NULL, customer_email VARCHAR(180) NOT NULL, customer_phone VARCHAR(40) DEFAULT NULL, customer_notes LONGTEXT DEFAULT NULL, slot_start DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', slot_end DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', order_number VARCHAR(255) DEFAULT NULL, voucher_code VARCHAR(32) DEFAULT NULL, options JSON NOT NULL, amount INT DEFAULT NULL, currency_code VARCHAR(3) NOT NULL, payment_state VARCHAR(30) DEFAULT NULL, postponed_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_MOMEO_BOOKING_REFERENCE (reference), UNIQUE INDEX uniq_momeo_booking_public_token (public_token), UNIQUE INDEX uniq_momeo_booking_staff_start (staff_member_id, slot_start), INDEX IDX_MOMEO_BOOKING_STAFF (staff_member_id), INDEX idx_momeo_booking_status (status), INDEX idx_momeo_booking_start (slot_start), INDEX idx_momeo_booking_service (service_code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE momeo_booking ADD CONSTRAINT FK_MOMEO_BOOKING_STAFF FOREIGN KEY (staff_member_id) REFERENCES momeo_staff_member (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE momeo_booking');
    }
}
