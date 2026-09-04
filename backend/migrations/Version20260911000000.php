<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add tenant-local waitlist requests and idempotent availability notifications'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE todatempo_waitlist_request (id INT AUTO_INCREMENT NOT NULL, unsubscribe_token VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, service_code VARCHAR(255) NOT NULL, service_name VARCHAR(255) NOT NULL, customer_first_name VARCHAR(100) NOT NULL, customer_last_name VARCHAR(100) NOT NULL, customer_email VARCHAR(180) NOT NULL, period_start DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', period_end DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', unsubscribed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_waitlist_unsubscribe_token (unsubscribe_token), INDEX idx_waitlist_matching (status, service_code, period_start, period_end), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE todatempo_waitlist_notification (id INT AUTO_INCREMENT NOT NULL, request_id INT NOT NULL, idempotency_key VARCHAR(64) NOT NULL, slot_start DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', slot_end DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_waitlist_notification_key (idempotency_key), INDEX IDX_WAITLIST_NOTIFICATION_REQUEST (request_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE todatempo_waitlist_notification ADD CONSTRAINT FK_WAITLIST_NOTIFICATION_REQUEST FOREIGN KEY (request_id) REFERENCES todatempo_waitlist_request (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE todatempo_waitlist_notification');
        $this->addSql('DROP TABLE todatempo_waitlist_request');
    }
}
