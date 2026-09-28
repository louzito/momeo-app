<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Controller\ShopSiteApiController;
use App\Entity\{SitePage, SiteMenu, SiteMenuItem, SiteMedia, SiteMediaUsage};
use App\Service\Site\{SiteMediaReferences, SiteDocumentValidator, SiteLinkResolver, SiteManagementService};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class SiteManagementTest extends TestCase
{
    private function tenant(): array
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true);
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [SitePage::class, SiteMenu::class, SiteMenuItem::class, SiteMedia::class, SiteMediaUsage::class]));
        $validator = new SiteDocumentValidator();
        $links = new SiteLinkResolver($em, $validator);
        return [new SiteManagementService($em, $validator, $links, new SiteMediaReferences($em)), $links, $em];
    }
    public function testPublicationIsIndependentOfDraftAndRestorable(): void
    {
        [$service, $links, $em] = $this->tenant();
        $page = $service->create(['title' => 'Bienvenue', 'slug' => 'bienvenue']);
        $public = new ShopSiteApiController($service, $links);
        self::assertSame(404, $public->page('bienvenue')->getStatusCode());
        $service->publish($page);
        $published = $page->getPublished();
        $service->update($page, array_replace($page->getDraft(), ['title' => 'Confidentiel']));
        self::assertSame('Bienvenue', json_decode($public->page('bienvenue')->getContent(), true)['title']);
        self::assertStringNotContainsString('Confidentiel', $public->page('bienvenue')->getContent());
        self::assertStringContainsString('no-store', $public->page('bienvenue')->headers->get('Cache-Control'));
        $service->restore($page);
        self::assertSame($published, $page->getDraft());
        $id = $page->getId();
        $em->clear();
        self::assertSame($published, $service->page($id)->getPublished());
        $service->archive($service->page($id));
        self::assertSame(404, $public->page('bienvenue')->getStatusCode());
    }
    public function testReferencesAndDataDoNotCrossTenantDatabases(): void
    {
        [$a] = $this->tenant();
        [$b, $links] = $this->tenant();
        $page = $a->create(['title' => 'Privé A', 'slug' => 'prive']);
        $a->publish($page);
        self::assertNull($b->page($page->getId()));
        self::assertNull($b->publishedPage('prive'));
        self::assertSame([], $b->pages());
        self::assertSame(404, (new ShopSiteApiController($b, $links))->page('prive')->getStatusCode());
        $this->expectException(\InvalidArgumentException::class);
        $b->saveMenu('main', ['items' => [['label' => 'Autre tenant', 'link' => ['type' => 'page', 'target' => $page->getId()]]]]);
    }
    public function testDuplicateSlugIsRefusedAndDuplicateRetainsDocumentOnly(): void
    {
        [$service] = $this->tenant();
        $page = $service->create(['title' => 'Accueil', 'slug' => 'maison', 'role' => 'home']);
        $draft = $page->getDraft();
        $draft['document']['blocks'][] = ['id' => 'intro', 'type' => 'heading', 'props' => ['text' => 'Bonjour', 'level' => 2]];
        $service->update($page, $draft);
        $service->publish($page);
        $copy = $service->duplicate($page, ['title' => 'Copie', 'slug' => 'copie']);
        self::assertSame($draft['document'], $copy->getDraft()['document']);
        self::assertNull($copy->getRole());
        self::assertNull($copy->getPublished());
        $this->expectException(\InvalidArgumentException::class);
        $service->create(['title' => 'Collision', 'slug' => 'maison']);
    }
    public function testInvalidUpdateLeavesExistingDocumentIntact(): void
    {
        [$service, , $em] = $this->tenant();
        $page = $service->create(['title' => 'Contact', 'slug' => 'contact']);
        $original = $page->getDraft();
        $invalid = $original;
        $invalid['document']['blocks'][] = ['id' => 'unsafe', 'type' => 'html', 'props' => ['html' => '<script>bad()</script>']];
        try { $service->update($page, $invalid); self::fail('Invalid document must be rejected'); }
        catch (\InvalidArgumentException) {
            $id = $page->getId();
            $em->clear();
            self::assertSame($original, $service->page($id)->getDraft());
        }
    }
    public function testSystemPagesCannotBeArchived(): void
    {
        [$service] = $this->tenant();
        $page = $service->create(['title' => 'Conditions', 'slug' => 'conditions', 'role' => 'terms']);
        $this->expectException(\InvalidArgumentException::class);
        $service->archive($page);
    }
    public function testMenuFollowsPublishedSlugAndKeepsHistoricAddress(): void
    {
        [$service, $links] = $this->tenant();
        $page = $service->create(['title' => 'Contact', 'slug' => 'contact']);
        $service->publish($page);
        $menu = $service->saveMenu('main', ['items' => [['label' => 'Contact', 'link' => ['type' => 'page', 'target' => $page->getId()]]]]);
        $service->publishMenu($menu);
        $service->update($page, array_replace($page->getDraft(), ['slug' => 'new-contact']));
        self::assertSame('contact', $links->menu($menu->getPublished(), true)[0]['url']);
        $service->publish($page);
        self::assertSame('new-contact', $links->menu($menu->getPublished(), true)[0]['url']);
        self::assertSame($page, $service->publishedPage('contact'));
        $this->expectException(\InvalidArgumentException::class);
        $service->create(['title' => 'Collision historique', 'slug' => 'contact']);
    }
    public function testBatchPublicationVisibilityPrimaryAndIndependentMenus(): void
    {
        [$service, $links, $em] = $this->tenant();
        $home = $service->create(['title' => 'Accueil', 'slug' => 'maison', 'role' => 'home']);
        $page = $service->create(['title' => 'Contact', 'slug' => 'contact']);
        $link = ['type' => 'page', 'target' => $page->getId()];
        $main = $service->saveMenu('main', ['items' => [
            ['label' => 'Contact', 'link' => $link],
            ['label' => 'Masqué', 'link' => $link, 'hidden' => true],
        ], 'primaryLink' => $link]);
        $footer = $service->saveMenu('footer', ['items' => [['label' => 'Boutique', 'link' => ['type' => 'route', 'target' => 'store']]]]);
        $public = new ShopSiteApiController($service, $links);
        $service->publishMenu($footer);
        self::assertNull(json_decode($public->navigation()->getContent(), true)['footer']);
        $service->publishBatch([$home->getId(), $page->getId()], ['main', 'footer']);
        $navigation = json_decode($public->navigation()->getContent(), true);
        self::assertCount(1, $navigation['main']);
        self::assertSame('contact', $navigation['primary']['url']);
        self::assertSame('shop?categorie=produits', $navigation['footer'][0]['url']);
        $service->deleteMenu('main');
        self::assertSame($navigation, json_decode($public->navigation()->getContent(), true));
        $service->publishMenu($main);
        self::assertSame([], json_decode($public->navigation()->getContent(), true)['main']);
        self::assertSame($navigation['footer'], json_decode($public->navigation()->getContent(), true)['footer']);
        $em->clear();
        self::assertNull($service->menu('main')->getPublishedPrimaryLink());
    }
    public function testFailedBatchDoesNotPublishAnyPageOrMenu(): void
    {
        [$service, , $em] = $this->tenant();
        $first = $service->create(['title' => 'Première', 'slug' => 'premiere']);
        $missing = $service->create(['title' => 'Autre', 'slug' => 'autre']);
        $menu = $service->saveMenu('main', ['items' => [], 'primaryLink' => ['type' => 'page', 'target' => $missing->getId()]]);
        try { $service->publishBatch([$first->getId()], ['main']); self::fail('Missing page must block the whole batch'); }
        catch (\InvalidArgumentException) {
            $id = $first->getId();
            $em->clear();
            self::assertNull($service->page($id)->getPublished());
            self::assertNull($service->menu('main')->getPublished());
        }
    }
    public function testArchivedPrimaryIsOmittedAndCannotBeRepublished(): void
    {
        [$service, $links] = $this->tenant();
        $home = $service->create(['title' => 'Accueil', 'slug' => 'maison', 'role' => 'home']);
        $page = $service->create(['title' => 'Contact', 'slug' => 'contact']);
        $menu = $service->saveMenu('main', ['items' => [], 'primaryLink' => ['type' => 'page', 'target' => $page->getId()]]);
        $service->publishBatch([$home->getId(), $page->getId()], ['main']);
        $service->archive($page);
        self::assertNull(json_decode((new ShopSiteApiController($service, $links))->navigation()->getContent(), true)['primary']);
        $this->expectException(\InvalidArgumentException::class);
        $service->publishMenu($menu);
    }
    public function testMenuPublicationOrderingAndArchival(): void
    {
        [$service, $links, $em] = $this->tenant();
        $page = $service->create(['title' => 'Contact', 'slug' => 'contact']);
        $service->publish($page);
        $items = [
            ['label' => 'Boutique', 'link' => ['type' => 'route', 'target' => 'shop'], 'children' => [['label' => 'Contact', 'link' => ['type' => 'page', 'target' => $page->getId()]]]],
            ['label' => 'Externe', 'link' => ['type' => 'external', 'target' => 'https://example.com']],
        ];
        $menu = $service->saveMenu('main', ['items' => $items]);
        $public = new ShopSiteApiController($service, $links);
        self::assertSame(404, $public->menu('main')->getStatusCode());
        $service->publishMenu($menu);
        $service->saveMenu('main', ['items' => []]);
        $em->clear();
        self::assertSame([], $service->menuItems($service->menu('main')));
        $published = json_decode($public->menu('main')->getContent(), true)['items'];
        self::assertSame(['Boutique', 'Externe'], array_column($published, 'label'));
        self::assertSame('contact', $published[0]['children'][0]['url']);
        $service->archive($service->page($page->getId()));
        self::assertSame([], json_decode($public->menu('main')->getContent(), true)['items'][0]['children']);
    }
    public function testArchivedLinkCanBeHiddenButForeignHiddenReferenceIsRejected(): void
    {
        [$service, $links] = $this->tenant();
        $page = $service->create(['title' => 'Contact', 'slug' => 'contact']);
        $service->archive($page);
        $menu = $service->saveMenu('main', ['items' => [['label' => 'Contact', 'hidden' => true, 'link' => ['type' => 'page', 'target' => $page->getId()]]]]);
        $service->publishMenu($menu);
        self::assertSame([], $links->menu($menu->getPublished(), true));
        [$other] = $this->tenant();
        $this->expectException(\InvalidArgumentException::class);
        $other->saveMenu('main', ['items' => $menu->getPublished()]);
    }
    public function testUnpublishedLinksPreventPublication(): void
    {
        [$service] = $this->tenant();
        $page = $service->create(['title' => 'Contact', 'slug' => 'contact']);
        $menu = $service->saveMenu('footer', ['items' => [['label' => 'Contact', 'link' => ['type' => 'page', 'target' => $page->getId()]]]]);
        try { $service->publishMenu($menu); self::fail('Expected unpublished reference rejection'); }
        catch (\InvalidArgumentException) { self::assertNull($menu->getPublished()); }
    }
}
