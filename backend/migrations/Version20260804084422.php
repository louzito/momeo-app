<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SkyBook — cheques cadeaux reels : table skybook_gift_voucher.
 * A appliquer sur TOUTES les BDD du registre (chaque tenant a ses cheques),
 * Y COMPRIS `template` (sinon les futurs clones du pool n'auraient pas la
 * table) et les pool-NNN deja clones. Voir scripts/migrate-all.sh.
 */
final class Version20260804084422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cheques cadeaux reels : cree la table skybook_gift_voucher (chantier 2026-08).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE skybook_gift_voucher (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, jump_type_code VARCHAR(255) NOT NULL, jump_type_name VARCHAR(255) NOT NULL, amount INT NOT NULL, currency_code VARCHAR(3) NOT NULL, purchaser_name VARCHAR(255) NOT NULL, purchaser_email VARCHAR(255) NOT NULL, beneficiary_name VARCHAR(255) DEFAULT NULL, beneficiary_email VARCHAR(255) NOT NULL, personal_message LONGTEXT DEFAULT NULL, purchase_order_number VARCHAR(255) NOT NULL, usage_order_number VARCHAR(255) DEFAULT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', activated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_7068AD5877153098 (code), INDEX idx_skybook_gift_voucher_status (status), INDEX idx_skybook_gift_voucher_purchaser_email (purchaser_email), INDEX idx_skybook_gift_voucher_beneficiary_email (beneficiary_email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE skybook_gift_voucher');
    }
}
