<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Entity\{SiteMenu, SitePage};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use PHPUnit\Framework\TestCase;

final class SiteDatabaseMappingTest extends TestCase
{
    public function testPagesAndMenusUseTheColumnsCreatedByTenantMigrations(): void
    {
        $em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true),
        );
        // Use the deployed SQL column names, independently of ORM SchemaTool.
        // A schema generated from the mappings would hide this regression.
        $em->getConnection()->executeStatement('CREATE TABLE todatempo_site_page (
            id VARCHAR(32) PRIMARY KEY, slug VARCHAR(120), role VARCHAR(20),
            draft JSON NOT NULL, published JSON, published_slug VARCHAR(120),
            previous_slugs JSON, legacy_published JSON, archived BOOLEAN NOT NULL,
            revision INTEGER NOT NULL DEFAULT 1
        )');
        $em->getConnection()->executeStatement('CREATE TABLE todatempo_site_menu (
            location VARCHAR(20) PRIMARY KEY, published JSON, primary_link JSON,
            published_primary_link JSON, revision INTEGER NOT NULL DEFAULT 1, draft_version INTEGER NOT NULL
        )');

        $page = new SitePage(['title' => 'Accueil', 'slug' => 'accueil']);
        $page->preserveLegacyPublished(['title' => 'Historique']);
        $page->publish();
        $page->revise(['title' => 'Bienvenue', 'slug' => 'bienvenue']);
        $page->publish();
        $em->persist($page);
        $link = ['type' => 'route', 'target' => 'booking'];
        foreach (['main', 'footer'] as $location) {
            $menu = new SiteMenu($location);
            $menu->setPrimaryLink($link);
            $menu->touchDraft();
            $menu->publish([]);
            $em->persist($menu);
        }
        $em->flush();
        $em->clear();

        $pages = $em->getRepository(SitePage::class)->findBy([], ['slug' => 'ASC']);
        self::assertCount(1, $pages);
        self::assertSame(['accueil'], $pages[0]->getPreviousSlugs());
        self::assertSame(['title' => 'Historique'], $pages[0]->getLegacyPublished());
        foreach (['main', 'footer'] as $location) {
            $menu = $em->find(SiteMenu::class, $location);
            self::assertSame($link, $menu->getPrimaryLink());
            self::assertSame($link, $menu->getPublishedPrimaryLink());
            self::assertSame(1, (int) $em->getConnection()->fetchOne(
                'SELECT draft_version FROM todatempo_site_menu WHERE location = ?', [$location],
            ));
        }
    }
}
