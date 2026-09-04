<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260909000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Track idempotent refunds and their credit-note audit trail'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_payment ADD refunded_amount INT DEFAULT 0 NOT NULL');
        $this->addSql("CREATE TABLE todatempo_refund_operation (id INT AUTO_INCREMENT NOT NULL, payment_id INT NOT NULL, order_id INT NOT NULL, idempotency_key VARCHAR(100) NOT NULL, amount INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(30) NOT NULL, provider VARCHAR(50) NOT NULL, provider_reference VARCHAR(255) DEFAULT NULL, credit_note_number VARCHAR(60) DEFAULT NULL, actor VARCHAR(180) NOT NULL, reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_todatempo_refund_key (idempotency_key), INDEX IDX_REFUND_PAYMENT (payment_id), INDEX IDX_REFUND_ORDER (order_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE todatempo_refund_operation ADD CONSTRAINT FK_REFUND_PAYMENT FOREIGN KEY (payment_id) REFERENCES sylius_payment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE todatempo_refund_operation ADD CONSTRAINT FK_REFUND_ORDER FOREIGN KEY (order_id) REFERENCES sylius_order (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE todatempo_refund_operation');
        $this->addSql('ALTER TABLE sylius_payment DROP refunded_amount');
    }
}
