<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store processed Stripe webhook event ids for durable idempotency';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE todatempo_stripe_webhook_event (id INT AUTO_INCREMENT NOT NULL, event_id VARCHAR(255) NOT NULL, type VARCHAR(100) NOT NULL, processed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_stripe_webhook_event_id (event_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE todatempo_stripe_webhook_event');
    }
}
