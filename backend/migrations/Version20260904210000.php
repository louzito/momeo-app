<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904210000 extends AbstractMigration
{
    public function getDescription(): string { return 'Keep the customer booking cancellation and rescheduling history'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE momeo_booking ADD change_history JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
        $this->addSql("UPDATE momeo_booking SET change_history = JSON_ARRAY() WHERE change_history IS NULL");
        $this->addSql("ALTER TABLE momeo_booking MODIFY change_history JSON NOT NULL COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE momeo_booking DROP change_history');
    }
}
