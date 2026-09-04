<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Separate services, options and physical products and track order fulfillment'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sylius_product ADD todatempo_type VARCHAR(20) DEFAULT 'service' NOT NULL, ADD pickup_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD delivery_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD delivery_fee INT DEFAULT 0 NOT NULL");
        $this->addSql("UPDATE sylius_product SET todatempo_type = 'option' WHERE code LIKE 'opt\\_%'");
        $this->addSql('ALTER TABLE sylius_order ADD fulfillment_mode VARCHAR(20) DEFAULT NULL, ADD preparation_state VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_todatempo_product_type ON sylius_product (todatempo_type)');
        $this->addSql('CREATE INDEX idx_todatempo_preparation ON sylius_order (preparation_state)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_todatempo_product_type ON sylius_product');
        $this->addSql('DROP INDEX idx_todatempo_preparation ON sylius_order');
        $this->addSql('ALTER TABLE sylius_product DROP todatempo_type, DROP pickup_enabled, DROP delivery_enabled, DROP delivery_fee');
        $this->addSql('ALTER TABLE sylius_order DROP fulfillment_mode, DROP preparation_state');
    }
}
