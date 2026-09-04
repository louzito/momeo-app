<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add tenant-local, pseudonymised GDPR operation audit trail'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE todatempo_gdpr_audit_log (id INT AUTO_INCREMENT NOT NULL, operation_id VARCHAR(64) NOT NULL, action VARCHAR(30) NOT NULL, subject_hash VARCHAR(64) DEFAULT NULL, actor VARCHAR(180) NOT NULL, details JSON NOT NULL COMMENT '(DC2Type:json)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_gdpr_audit_operation (operation_id), INDEX idx_gdpr_audit_created (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE todatempo_gdpr_audit_log'); }
}
