<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée le modèle métier de planning récurrent et importe sans perte les taxons planning_*.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS momeo_planning (id INT AUTO_INCREMENT NOT NULL, staff_member_id INT DEFAULT NULL, code VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, timezone VARCHAR(64) NOT NULL, days JSON NOT NULL, capacity INT NOT NULL, service_codes JSON NOT NULL, active TINYINT(1) NOT NULL, legacy_config JSON DEFAULT NULL, legacy_taxon_code VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_momeo_planning_code (code), UNIQUE INDEX UNIQ_MOMEO_PLANNING_LEGACY (legacy_taxon_code), INDEX idx_momeo_planning_active (active), INDEX IDX_MOMEO_PLANNING_STAFF (staff_member_id), PRIMARY KEY(id), CONSTRAINT FK_MOMEO_PLANNING_STAFF FOREIGN KEY (staff_member_id) REFERENCES momeo_staff_member (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $taxons = $this->connection->fetchAllAssociative("SELECT t.code, t.enabled, tr.name, tr.description FROM sylius_taxon t LEFT JOIN sylius_taxon_translation tr ON tr.translatable_id = t.id AND tr.locale = 'en_US' WHERE t.code LIKE 'planning\\_%'");
        foreach ($taxons as $taxon) {
            $config = json_decode((string) ($taxon['description'] ?? ''), true);
            $config = \is_array($config) ? $config : ['_rawDescription' => $taxon['description']];
            // INSERT IGNORE makes the import replayable and never overwrites later business edits.
            $this->addSql(
                'INSERT IGNORE INTO momeo_planning (code, name, timezone, days, capacity, service_codes, active, legacy_config, legacy_taxon_code, created_at, updated_at) VALUES (:code, :name, :timezone, :days, :capacity, :services, :active, :legacy, :legacyCode, NOW(), NOW())',
                ['code' => $taxon['code'], 'name' => trim((string) ($config['name'] ?? $taxon['name'] ?? $taxon['code'])) ?: $taxon['code'], 'timezone' => 'Europe/Paris',
                    'days' => json_encode($this->recurringDays($config), JSON_THROW_ON_ERROR), 'capacity' => max(1, (int) ($config['capacity'] ?? 1)),
                    'services' => json_encode(\is_array($config['jumpCodes'] ?? null) ? array_values($config['jumpCodes']) : [], JSON_THROW_ON_ERROR),
                    'active' => (int) $taxon['enabled'], 'legacy' => json_encode($config, JSON_THROW_ON_ERROR), 'legacyCode' => $taxon['code']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS momeo_planning');
    }

    /** @param array<string, mixed> $config
     *  @return array<string, list<array{start: string, end: string}>>
     */
    private function recurringDays(array $config): array
    {
        $result = [];
        $numberToDay = [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday'];
        if (\is_array($config['openDays'] ?? null) && \is_array($config['times'] ?? null)) {
            foreach ($config['openDays'] as $number) foreach ($config['times'] as $time) {
                if (isset($numberToDay[(int) $number]) && $this->validTime((string) $time)) $result[$numberToDay[(int) $number]][] = $this->oneHourRange((string) $time);
            }
        }
        if (\is_array($config['days'] ?? null)) foreach ($config['days'] as $date => $times) {
            if (!\is_array($times) || \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date) === false) continue;
            $weekday = strtolower((new \DateTimeImmutable((string) $date))->format('l'));
            foreach ($times as $time) if ($this->validTime((string) $time)) $result[$weekday][] = $this->oneHourRange((string) $time);
        }
        foreach ($result as &$ranges) $ranges = array_values(array_unique($ranges, SORT_REGULAR));
        return $result;
    }

    /** @return array{start: string, end: string} */
    private function oneHourRange(string $time): array
    {
        $end = (new \DateTimeImmutable('2000-01-01 '.$time))->modify('+1 hour')->format('H:i');
        return ['start' => $time, 'end' => $end === '00:00' ? '23:59' : $end];
    }

    private function validTime(string $time): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
    }
}
