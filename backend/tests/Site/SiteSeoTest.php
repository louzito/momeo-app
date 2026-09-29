<?php

declare(strict_types=1);
namespace App\Tests\Site;

use App\Controller\SiteHtmlController;
use App\Entity\{SitePage, SiteMedia, SiteMediaUsage};
use App\Service\Site\{SiteDocumentValidator, SiteLinkResolver, SiteManagementService, SiteMediaReferences, SiteSeoService, SiteHtmlRenderer};
use App\Service\Tenant\{TenantContext, TenantIdentifierResolver, TenantRegistry, TenantUrlGenerator};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SiteSeoTest extends TestCase
{
    private string $registryFile;
    protected function setUp(): void
    {
        $this->registryFile = tempnam(sys_get_temp_dir(), 'site-seo-');
        file_put_contents($this->registryFile, json_encode([
            'alpha' => ['db' => 'alpha_db', 'status' => 'active'],
            'beta' => ['db' => 'beta_db', 'status' => 'active', 'customDomain' => ['host' => 'beta.example.test', 'verifiedAt' => '2026-09-29']],
        ]));
    }
    protected function tearDown(): void { unlink($this->registryFile); }
    private function tenant(string $slug = 'alpha', ?string $node = null): array
    {
        $registry = new TenantRegistry($this->registryFile, false);
        $context = new TenantContext($registry, new TenantIdentifierResolver(), 'alpha'); $context->setSlug($slug);
        $seo = new SiteSeoService($context, new TenantUrlGenerator($registry, 'https://app.example.test/prefix'));
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true));
        (new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [SitePage::class, SiteMedia::class, SiteMediaUsage::class]));
        $validator = new SiteDocumentValidator(); $links = new SiteLinkResolver($em, $validator);
        $site = new SiteManagementService($em, $validator, $links, new SiteMediaReferences($em), null, $seo);
        $renderer = new SiteHtmlRenderer(dirname(__DIR__, 2), $node ?? (getenv('TODATEMPO_SITE_NODE_BINARY') ?: 'node'));
        return [$site, new SiteHtmlController($site, $seo, $renderer, $context, $registry), $em, $seo];
    }
    private function get(SiteHtmlController $controller, string $path = ''): \Symfony\Component\HttpFoundation\Response
    {
        return $controller->page(Request::create('https://untrusted.example.test/api/v2/shop/site/html/'.$path), $path);
    }
    private function page(SiteManagementService $site): SitePage
    {
        $page = $site->create(['title' => 'Notre établissement', 'slug' => 'contact']);
        $draft = $page->getDraft();
        $draft['document']['blocks'] = [
            ['id' => 'text', 'type' => 'text', 'props' => ['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bienvenue chez nous & prenez le temps.']]]]]]],
            ['id' => 'hidden', 'type' => 'heading', 'hidden' => true, 'props' => ['level' => 2, 'text' => 'SECRET MASQUÉ']],
            ['id' => 'link', 'type' => 'button', 'props' => ['label' => 'Réserver', 'link' => ['type' => 'route', 'target' => 'shop']]],
        ];
        $site->update($page, $draft);
        return $page;
    }
    public function testInitialHtmlPublicationSlugRedirectAndArchiveWithoutRebuild(): void
    {
        [$site, $http] = $this->tenant(); $page = $this->page($site);
        self::assertSame(404, $this->get($http, 'contact')->getStatusCode());
        self::assertStringNotContainsString('contact', $this->get($http, 'sitemap.xml')->getContent());
        $site->publish($page);
        $response = $this->get($http, 'contact'); $html = $response->getContent();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Notre établissement</title>', $html);
        self::assertStringContainsString('name="todatempo-app-base" content="/prefix/"', $html);
        self::assertStringContainsString('<p>Bienvenue chez nous &amp; prenez le temps.</p>', $html);
        self::assertStringContainsString('name="description" content="Bienvenue chez nous &amp; prenez le temps."', $html);
        self::assertStringContainsString('href="https://app.example.test/prefix/alpha/shop"', $html);
        self::assertStringContainsString('property="og:url" content="https://app.example.test/prefix/alpha/contact"', $html);
        self::assertStringNotContainsString('SECRET MASQUÉ', $html);
        self::assertStringNotContainsString('untrusted.example.test', $html);
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $draft = $page->getDraft(); $draft['title'] = 'NOUVEAU BROUILLON'; $draft['slug'] = 'rencontre';
        $site->update($page, $draft);
        self::assertStringNotContainsString('NOUVEAU BROUILLON', $this->get($http, 'contact')->getContent());
        self::assertSame(404, $this->get($http, 'rencontre')->getStatusCode());
        $site->publish($page);
        self::assertStringContainsString('NOUVEAU BROUILLON', $this->get($http, 'rencontre')->getContent());
        $redirect = $this->get($http, 'contact');
        self::assertSame(301, $redirect->getStatusCode());
        self::assertSame('https://app.example.test/prefix/alpha/rencontre', $redirect->headers->get('Location'));
        $map = $this->get($http, 'sitemap.xml')->getContent();
        self::assertStringContainsString('/alpha/rencontre', $map); self::assertStringNotContainsString('/contact', $map);
        $site->archive($page);
        self::assertSame(404, $this->get($http, 'contact')->getStatusCode());
        self::assertSame(404, $this->get($http, 'rencontre')->getStatusCode());
        self::assertStringNotContainsString('rencontre', $this->get($http, 'sitemap.xml')->getContent());
    }
    public function testSharingImageIsTenantScopedAndRetainedByPublication(): void
    {
        [$site, $http, $em] = $this->tenant('beta');
        $image = new SiteMedia('beta/share.webp', 'beta/thumb.webp', 1000, 500, '');
        $em->persist($image); $em->flush();
        $page = $this->page($site); $draft = $page->getDraft();
        $draft['seo'] = ['title' => 'Titre "partagé"', 'description' => 'Description & partage', 'imageId' => $image->getId()];
        $site->update($page, $draft); $site->publish($page);
        $html = $this->get($http, 'contact')->getContent();
        self::assertStringContainsString('property="og:image" content="https://beta.example.test/media/image/beta/share.webp"', $html);
        self::assertStringContainsString('<title>Titre &quot;partagé&quot;</title>', $html);
        self::assertStringNotContainsString('app.example.test', $html);
        self::assertStringContainsString('name="todatempo-app-base" content="/"', $html);
        unset($draft['seo']['imageId']); $site->update($page, $draft);
        self::assertSame(1, $em->getRepository(SiteMediaUsage::class)->count([]));
        [$other] = $this->tenant(); $otherPage = $this->page($other); $draft = $otherPage->getDraft(); $draft['seo']['imageId'] = $image->getId();
        $this->expectException(\InvalidArgumentException::class); $other->update($otherPage, $draft);
    }
    public function testTenantIsolationAndPrivateRoutes(): void
    {
        [$site, $alpha] = $this->tenant(); [$other, $beta] = $this->tenant('beta');
        $page = $this->page($site); $site->publish($page);
        self::assertSame(404, $this->get($beta, 'contact')->getStatusCode());
        self::assertStringNotContainsString('alpha', $this->get($beta, 'sitemap.xml')->getContent());
        foreach (['admin/site/preview/123', 'account', 'checkout/details'] as $path) {
            $response = $this->get($alpha, $path);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
            self::assertStringNotContainsString('Bienvenue chez nous', $response->getContent());
            self::assertStringNotContainsString($path, $this->get($alpha, 'sitemap.xml')->getContent());
        }
        foreach (['absent/absent', 'admin/inconnu', 'account/inconnu', 'beneficiary/inconnu'] as $path) self::assertSame(404, $this->get($alpha, $path)->getStatusCode());
        $this->expectException(NotFoundHttpException::class);
        $alpha->page(Request::create('https://beta.example.test/'), 'contact');
    }
    public function testDynamicCatalogHasNoStaleCommercialSnapshot(): void
    {
        [$site, $http] = $this->tenant(); $page = $this->page($site); $draft = $page->getDraft();
        $draft['document']['blocks'][] = ['id' => 'offers', 'type' => 'catalog', 'props' => ['title' => 'Offres', 'mode' => 'category', 'category' => 'prestations', 'codes' => [], 'limit' => 3, 'buttonLabel' => 'Voir']];
        $site->update($page, $draft); $site->publish($page);
        self::assertStringContainsString('Consulter les offres et disponibilités', $this->get($http, 'contact')->getContent());
    }
    public function testAutomaticImageAndEscapingInSharedVueRenderer(): void
    {
        [$site, $http, $em, $seo] = $this->tenant();
        $image = new SiteMedia('alpha/photo.webp', 'alpha/thumb.webp', 800, 400, '');
        $em->persist($image); $em->flush();
        $page = $this->page($site); $draft = $page->getDraft();
        $draft['document']['blocks'][] = ['id' => 'photo', 'type' => 'image', 'props' => ['mediaId' => $image->getId(), 'alt' => 'Lieu & détente']];
        $site->update($page, $draft); $site->publish($page);
        $html = $this->get($http, 'contact')->getContent();
        self::assertStringContainsString('src="https://app.example.test/prefix/media/image/alpha/photo.webp"', $html);
        self::assertStringContainsString('property="og:image" content="https://app.example.test/prefix/media/image/alpha/photo.webp"', $html);
        // Defense in depth: even a historical corrupt snapshot cannot create HTML/script tags.
        $rendered = $site->render($page);
        $rendered['title'] = '</title><script>alert(1)</script>';
        $rendered['document']['blocks'][0]['props']['content']['content'][0]['content'][0]['text'] = '<img src=x onerror=alert(1)>';
        $renderer = new SiteHtmlRenderer(dirname(__DIR__, 2), getenv('TODATEMPO_SITE_NODE_BINARY') ?: 'node');
        $html = $renderer->render($rendered, $seo->metadata($rendered), $seo->base());
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }
    public function testHomeAndLegalCanonicalsDoNotExposeStorageSlugs(): void
    {
        [$site, $http] = $this->tenant();
        foreach (['home' => '', 'terms' => 'legal/terms', 'mentions' => 'legal/mentions'] as $role => $path) {
            $page = $site->create(['title' => $role, 'slug' => 'stored-'.$role, 'role' => $role]); $site->publish($page);
            self::assertSame(200, $this->get($http, $path)->getStatusCode());
            self::assertSame('https://app.example.test/prefix/alpha/'.$path, $this->get($http, 'stored-'.$role)->headers->get('Location'));
        }
        self::assertStringNotContainsString('stored-', $this->get($http, 'sitemap.xml')->getContent());
        self::assertSame(301, $this->get($http, 'accueil')->getStatusCode());
    }
    public function testUnavailableRendererReturns503InsteadOfIndexableEmptyHtml(): void
    {
        [$site, $http] = $this->tenant('alpha', '/missing-node'); $page = $this->page($site); $site->publish($page);
        $response = $this->get($http, 'contact');
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
    }
}
