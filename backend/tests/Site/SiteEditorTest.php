<?php
namespace App\Tests\Site;

use App\Controller\AdminSiteApiController;
use App\Entity\{SitePage, SiteMenu, SiteMenuItem, SiteMedia, SiteMediaUsage};
use App\Service\Site\{SiteManagementService, SiteDocumentValidator, SiteLinkResolver, SiteMediaReferences};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteEditorTest extends TestCase
{
    public function testStaleBrowserCannotOverwriteDraftAndHiddenContentIsNotPublic(): void
    {
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true));
        (new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [SitePage::class, SiteMenu::class, SiteMenuItem::class, SiteMedia::class, SiteMediaUsage::class]));
        $validator = new SiteDocumentValidator(); $links = new SiteLinkResolver($em, $validator);
        $service = new SiteManagementService($em, $validator, $links, new SiteMediaReferences($em));
        $controller = new AdminSiteApiController($service);
        $page = $service->create(['title' => 'Contact', 'slug' => 'contact']);
        $draft = $page->getDraft(); $revision = $page->getRevision();
        $draft['document']['blocks'] = [['id' => 'secret', 'type' => 'faq', 'hidden' => true, 'props' => ['title' => 'Texte confidentiel', 'items' => []]]];
        $put = fn (int $version) => $controller->update($page->getId(), Request::create('/', 'PUT', [], [], [], [], json_encode($draft + ['revision' => $version])));
        self::assertSame(200, $put($revision)->getStatusCode());
        self::assertSame(409, $put($revision)->getStatusCode());
        self::assertNull($page->getPublished());
        $service->publish($page);
        $response = (new \App\Controller\ShopSiteApiController($service, $links))->page('contact');
        self::assertStringNotContainsString('Texte confidentiel', $response->getContent());
        self::assertCount(1, $page->getDraft()['document']['blocks']);
    }
    public function testRichLinksAndDeepStructuresCannotInjectCode(): void
    {
        $validator = new SiteDocumentValidator();
        foreach (['javascript:alert(1)', 'data:text/html,test', 'https://user:password@example.org'] as $href) {
            $page = ['title' => 'Contact', 'slug' => 'contact', 'seo' => ['title' => '', 'description' => ''], 'document' => ['schemaVersion' => 1, 'blocks' => [['id' => 'text', 'type' => 'text', 'props' => ['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Lien', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]]]]]]]]]];
            try { $validator->page($page); self::fail('Unsafe link accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
