<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Cartes cadeaux monétaires et mouvements, sans conversion des anciens bons'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_order ADD gift_card_amount INT DEFAULT NULL');
        $this->addSql("CREATE TABLE todatempo_gift_card (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(32) NOT NULL, establishment VARCHAR(100) NOT NULL, channel_code VARCHAR(255) NOT NULL, currency VARCHAR(3) NOT NULL, initial_amount INT NOT NULL, available INT NOT NULL, reserved INT NOT NULL, status VARCHAR(20) NOT NULL, purchase_order_number VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_gift_card_code (code), UNIQUE INDEX uniq_gift_card_purchase (purchase_order_number), PRIMARY KEY(id), CONSTRAINT chk_gift_card_balance CHECK (initial_amount > 0 AND available >= 0 AND reserved >= 0 AND available + reserved <= initial_amount)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE todatempo_gift_card_movement (id INT AUTO_INCREMENT NOT NULL, card_id INT NOT NULL, operation_key VARCHAR(64) NOT NULL, kind VARCHAR(10) NOT NULL, reference VARCHAR(255) DEFAULT NULL, order_number VARCHAR(255) NOT NULL, amount INT NOT NULL, available_after INT NOT NULL, reserved_after INT NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_GIFT_CARD (card_id), UNIQUE INDEX uniq_gift_card_operation (card_id, operation_key), INDEX idx_gift_card_order (order_number), PRIMARY KEY(id), CONSTRAINT fk_gift_card_movement FOREIGN KEY (card_id) REFERENCES todatempo_gift_card (id) ON DELETE RESTRICT, CONSTRAINT chk_gift_card_movement CHECK (amount > 0 AND available_after >= 0 AND reserved_after >= 0)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les cartes vendues et leur historique doivent être conservés.');
    }
}
