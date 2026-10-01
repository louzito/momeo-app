<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rendre visibles les prestations créées en anglais dans la langue de leur canal de vente.';
    }

    public function up(Schema $schema): void
    {
        // Conserver les traductions existantes et les donnees historiques.
        $this->addSql(<<<'SQL'
INSERT INTO sylius_product_translation (translatable_id, locale, name, slug, description, short_description)
SELECT DISTINCT source.translatable_id, locale.code, source.name, source.slug, source.description, source.short_description
FROM sylius_product_translation source
INNER JOIN sylius_product product ON product.id = source.translatable_id
INNER JOIN sylius_product_channels product_channel ON product_channel.product_id = product.id
INNER JOIN sylius_channel channel ON channel.id = product_channel.channel_id
INNER JOIN sylius_locale locale ON locale.id = channel.default_locale_id
LEFT JOIN sylius_product_translation existing ON existing.translatable_id = product.id AND existing.locale = locale.code
WHERE source.locale = 'en_US' AND existing.id IS NULL
AND (LEFT(product.code, 8) = 'service_' OR LEFT(product.code, 5) = 'jump_')
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO sylius_product_variant_translation (translatable_id, locale, name)
SELECT DISTINCT source.translatable_id, locale.code, source.name
FROM sylius_product_variant_translation source
INNER JOIN sylius_product_variant variant ON variant.id = source.translatable_id
INNER JOIN sylius_product product ON product.id = variant.product_id
INNER JOIN sylius_product_channels product_channel ON product_channel.product_id = product.id
INNER JOIN sylius_channel channel ON channel.id = product_channel.channel_id
INNER JOIN sylius_locale locale ON locale.id = channel.default_locale_id
LEFT JOIN sylius_product_variant_translation existing ON existing.translatable_id = variant.id AND existing.locale = locale.code
WHERE source.locale = 'en_US' AND existing.id IS NULL
AND (LEFT(product.code, 8) = 'service_' OR LEFT(product.code, 5) = 'jump_')
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les traductions des prestations, qui peuvent avoir été modifiées.');
    }
}
