<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904200000 extends AbstractMigration
{
    public function getDescription(): string { return 'Store booking total and on-site balance in cents'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE momeo_booking ADD total_amount INT DEFAULT NULL, ADD balance_due INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE momeo_booking DROP total_amount, DROP balance_due');
    }
}
