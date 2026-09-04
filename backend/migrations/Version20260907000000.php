<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add team roles and optional administrator-to-staff link'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sylius_admin_user ADD team_role VARCHAR(20) DEFAULT 'practitioner' NOT NULL, ADD staff_member_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE sylius_admin_user ADD CONSTRAINT FK_TEAM_ADMIN_STAFF FOREIGN KEY (staff_member_id) REFERENCES momeo_staff_member (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TEAM_ADMIN_STAFF ON sylius_admin_user (staff_member_id)');
        // Every existing tenant keeps an enabled owner, deterministically choosing the oldest account.
        $this->addSql("UPDATE sylius_admin_user SET team_role = 'owner' WHERE enabled = 1 ORDER BY id ASC LIMIT 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_admin_user DROP FOREIGN KEY FK_TEAM_ADMIN_STAFF');
        $this->addSql('DROP INDEX UNIQ_TEAM_ADMIN_STAFF ON sylius_admin_user');
        $this->addSql('ALTER TABLE sylius_admin_user DROP team_role, DROP staff_member_id');
    }
}
