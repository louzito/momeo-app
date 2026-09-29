<?php

declare(strict_types=1);
namespace App\Tests\Site;

use App\Controller\{AdminSiteApiController, ShopSiteApiController};
use App\Entity\{SitePage, SiteMenu, SiteMenuItem, SiteMedia, SiteMediaUsage};
use App\Service\Site\{SiteDocumentValidator, SiteLinkResolver, SiteManagementService, SiteMediaReferences};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SitePublicationTest extends TestCase
{
    private function tenant(?string $file = null, bool $create = true): array
    {
        $em = new EntityManager(DriverManager::getConnection($file ? ['driver' => 'pdo_sqlite', 'path' => $file] : ['driver' => 'pdo_sqlite', 'memory' => true]), ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true));
        if ($create) (new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [SitePage::class, SiteMenu::class, SiteMenuItem::class, SiteMedia::class, SiteMediaUsage::class]));
        $validator = new SiteDocumentValidator(); $links = new SiteLinkResolver($em, $validator);
        $service = new SiteManagementService($em, $validator, $links, new SiteMediaReferences($em));
        return [$service, new AdminSiteApiController($service), new ShopSiteApiController($service, $links), $em];
    }
    private function request(array $pages = [], array $menus = []): Request
    {
        return Request::create('/', 'POST', [], [], [], [], json_encode(['pages' => array_map(static fn ($p) => ['id' => $p->getId(), 'revision' => $p->getRevision()], $pages), 'menus' => array_map(static fn ($m) => ['id' => $m->getLocation(), 'revision' => $m->getRevision()], $menus)]));
    }
    public function testPrivatePreviewAndAtomicHttpPublication(): void
    {
        [$site, $admin, $public, $em] = $this->tenant();
        $home = $site->create(['title' => 'Accueil', 'slug' => 'bienvenue', 'role' => 'home']);
        $contact = $site->create(['title' => 'Contact privé', 'slug' => 'contact']);
        $menu = $site->saveMenu('main', ['items' => [['label' => 'Contact', 'link' => ['type' => 'page', 'target' => $contact->getId()]]]]);
        self::assertSame(404, $public->role('home')->getStatusCode());
        $preview = $admin->preview($contact->getId());
        self::assertSame('Contact privé', json_decode($preview->getContent(), true)['title']);
        self::assertStringContainsString('noindex', $preview->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));
        self::assertSame(404, $public->page('contact')->getStatusCode());
        // Validation happens before any snapshot changes, inside the transaction.
        self::assertSame(422, $admin->publish($this->request([$home], [$menu]))->getStatusCode());
        $em->clear();
        self::assertNull($site->page($home->getId())->getPublished());
        // A failed transaction closes Doctrine: emulate the next HTTP request.
        self::assertNull($home->getPublished());
    }
    public function testPreviewOfSelectedMenusUsesPrivateDestinationsAndMatchesPublishedSections(): void
    {
        [$site, $admin, $public] = $this->tenant();
        $home = $site->create(['title' => 'Accueil', 'slug' => 'bienvenue', 'role' => 'home']);
        $page = $site->create(['title' => 'Contact', 'slug' => 'contact']);
        $link = ['type' => 'page', 'target' => $page->getId()];
        $menu = $site->saveMenu('main', ['items' => [['label' => 'Visible', 'link' => $link], ['label' => 'Masqué', 'link' => $link, 'hidden' => true]], 'primaryLink' => $link]);
        $preview = json_decode($admin->preview($home->getId(), Request::create('/?menus=main'))->getContent(), true);
        self::assertCount(1, $preview['navigation']['main']);
        self::assertSame('admin/site/preview/'.$page->getId(), $preview['navigation']['main'][0]['url']);
        self::assertSame($preview['navigation']['main'][0]['url'], $preview['navigation']['primary']['url']);
        self::assertNull(json_decode($public->navigation()->getContent(), true)['main']);
        $site->publishBatch([$home->getId(), $page->getId()], ['main']);
        $published = json_decode($public->role('home')->getContent(), true);
        self::assertSame($preview['document'], $published['document']);
        self::assertSame($preview['media'], $published['media']);
        self::assertSame(422, $admin->preview($home->getId(), Request::create('/?menus=other'))->getStatusCode());
    }
    public function testSuccessfulPublicationAndRestorationStayIndependentOfPublicDrafts(): void
    {
        [$site, $admin, $public] = $this->tenant();
        $home = $site->create(['title' => 'Accueil', 'slug' => 'bienvenue', 'role' => 'home']);
        $page = $site->create(['title' => 'Contact', 'slug' => 'contact']);
        $menu = $site->saveMenu('main', ['items' => [['label' => 'Contact', 'link' => ['type' => 'page', 'target' => $page->getId()]]]]);
        self::assertSame(200, $admin->publish($this->request([$home, $page], [$menu]))->getStatusCode());
        self::assertSame(200, $public->role('home')->getStatusCode());
        $before = $public->navigation()->getContent();
        $site->saveMenu('main', ['items' => []]);
        $site->update($page, array_replace($page->getDraft(), ['title' => 'Confidentiel']));
        self::assertSame($before, $public->navigation()->getContent());
        self::assertStringNotContainsString('Confidentiel', $public->page('contact')->getContent());
        $site->restoreMenu('main', $menu->getRevision());
        $restore = Request::create('/', 'POST', [], [], [], [], json_encode(['revision' => $page->getRevision()]));
        self::assertSame(200, $admin->restore($page->getId(), $restore)->getStatusCode());
        self::assertSame($page->getPublished(), $page->getDraft());
        self::assertSame($menu->getPublished(), $site->menuItems($menu));
        self::assertSame($before, $public->navigation()->getContent());
    }
    public function testConcurrentEditorInvalidatesWholePublicationIncludingMenuOnlyChanges(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'site-concurrency-');
        try {
            [$first, $admin, , $em1] = $this->tenant($file);
            $page = $first->create(['title' => 'Contact', 'slug' => 'contact']);
            $menu = $first->saveMenu('main', ['items' => []]);
            $request = $this->request([$page], [$menu]);
            [$second, , , $em2] = $this->tenant($file, false);
            // Second connection changes only child rows. The menu version must still move.
            $second->saveMenu('main', ['items' => [['label' => 'Boutique', 'link' => ['type' => 'route', 'target' => 'shop']]]]);
            self::assertSame(409, $admin->publish($request)->getStatusCode());
            $em2->clear();
            self::assertNull($second->page($page->getId())->getPublished());
            self::assertNull($second->menu('main')->getPublished());
            $em1->getConnection()->close(); $em2->getConnection()->close();
        } finally { unlink($file); }
    }
    public function testConcurrentPagePublicationRefusesAStaleBrowser(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'site-concurrency-');
        try {
            [$first, $admin, , $em1] = $this->tenant($file);
            $page = $first->create(['title' => 'Contact', 'slug' => 'contact']);
            $request = $this->request([$page]);
            [$second, , , $em2] = $this->tenant($file, false);
            $other = $second->page($page->getId());
            $second->update($other, array_replace($other->getDraft(), ['title' => 'Autre fenêtre']));
            $second->publish($other);
            self::assertSame(409, $admin->publish($request)->getStatusCode());
            $em2->clear();
            self::assertSame('Autre fenêtre', $second->page($page->getId())->getPublished()['title']);
            $em1->getConnection()->close(); $em2->getConnection()->close();
        } finally { unlink($file); }
    }
    public function testNoPublicCacheCanCarryDraftOrAnotherTenantPublication(): void
    {
        [$a, $adminA, $publicA] = $this->tenant();
        [$b, , $publicB] = $this->tenant();
        foreach ([$a, $b] as $i => $site) {
            $page = $site->create(['title' => $i ? 'Centre B' : 'Centre A', 'slug' => 'bienvenue', 'role' => 'home']);
            $site->publish($page);
        }
        $home = $a->rolePage('home');
        $a->update($home, array_replace($home->getDraft(), ['title' => 'Nouveau A']));
        $adminA->publish($this->request([$home]));
        self::assertStringContainsString('Nouveau A', $publicA->role('home')->getContent());
        self::assertStringContainsString('Centre B', $publicB->role('home')->getContent());
        foreach ([$publicA->role('home'), $publicB->role('home'), $publicA->navigation()] as $response) {
            self::assertStringContainsString('private', $response->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        self::assertSame(404, $publicB->page('inexistant')->getStatusCode());
    }
}
