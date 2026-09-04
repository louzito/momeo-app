<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add configurable appointment reminder consent and delivery tracking'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE momeo_booking ADD sms_reminder_consent TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("CREATE TABLE todatempo_reminder_delivery (id INT AUTO_INCREMENT NOT NULL, booking_id INT NOT NULL, idempotency_key VARCHAR(64) NOT NULL, channel VARCHAR(10) NOT NULL, hours_before INT NOT NULL, status VARCHAR(20) NOT NULL, attempts INT NOT NULL, provider_reference VARCHAR(255) DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_reminder_idempotency_key (idempotency_key), INDEX idx_reminder_status (status), INDEX IDX_REMINDER_BOOKING (booking_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE todatempo_reminder_delivery ADD CONSTRAINT FK_REMINDER_BOOKING FOREIGN KEY (booking_id) REFERENCES momeo_booking (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE todatempo_reminder_delivery');
        $this->addSql('ALTER TABLE momeo_booking DROP sms_reminder_consent');
    }
}
