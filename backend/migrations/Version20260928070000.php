<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928070000 extends AbstractMigration
{
    public function getDescription(): string { return 'Rattachement explicite des cartes cadeaux au compte bénéficiaire'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todatempo_gift_card ADD beneficiary_id INT DEFAULT NULL, ADD INDEX idx_gift_card_beneficiary (beneficiary_id), ADD CONSTRAINT fk_gift_card_beneficiary FOREIGN KEY (beneficiary_id) REFERENCES sylius_customer (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les rattachements des cartes aux comptes.');
    }
}
