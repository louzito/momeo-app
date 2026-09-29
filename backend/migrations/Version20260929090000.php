<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260929090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Panier commun : reprise idempotente et plusieurs cartes cadeaux par commande'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_order ADD checkout_key VARCHAR(64) DEFAULT NULL, ADD checkout_context JSON DEFAULT NULL, ADD UNIQUE INDEX uniq_checkout_key (checkout_key)');
        $this->addSql('ALTER TABLE todatempo_gift_card ADD purchase_line INT NOT NULL DEFAULT 0, DROP INDEX uniq_gift_card_purchase, ADD UNIQUE INDEX gift_card_purchase_line (purchase_order_number, purchase_line)');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver les commandes et cartes vendues.'); }
}
