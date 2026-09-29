<?php

declare(strict_types=1);
namespace App\Tests\Site;

use App\Entity\{SitePage, SiteMenu, SiteMenuItem, SiteMedia, SiteMediaUsage};
use App\Entity\Taxonomy\Taxon;
use App\Entity\Channel\Channel;
use App\Service\Site\{SiteLegacyImportService, SiteMediaService, SiteDocumentValidator, SiteLinkResolver, SiteManagementService, SiteMediaReferences};
use App\Service\Security\ImageUploadValidator;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, EntityManagerInterface, EntityRepository, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;

final class SiteLegacyImportTest extends TestCase
{
    public function testImportTwiceKeepsOldPublishedAndDraftApartAndDoesNotReplaceCreatedPages(): void
    {
        $real = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true));
        (new SchemaTool($real))->createSchema(array_map($real->getClassMetadata(...), [SitePage::class, SiteMenu::class, SiteMenuItem::class, SiteMedia::class, SiteMediaUsage::class]));
        $published = ['home' => ['title' => 'Accueil public', 'subtitle' => 'Texte public', 'highlights' => ['Notre salle']], 'legal' => ['terms' => ['enabled' => true, 'content' => "Conditions publiques\nDeuxième ligne"]], 'colors' => ['header' => '#123456']];
        $draft = array_replace_recursive($published, ['home' => ['title' => 'Accueil confidentiel'], 'legal' => ['terms' => ['content' => 'Conditions confidentielles']]]);
        $raw = json_encode(['schemaVersion' => 1, 'draft' => $draft, 'published' => $published]);
        $taxon = new Taxon(); $taxon->setCurrentLocale('en_US'); $taxon->setFallbackLocale('en_US');
        $taxon->getTranslation('en_US')->setDescription($raw);
        $taxons = $this->createStub(EntityRepository::class); $taxons->method('findOneBy')->willReturn($taxon);
        $channels = $this->createStub(EntityRepository::class); $channels->method('findOneBy')->willReturn(null);
        // Historical Sylius configuration fixture; all new site records use real SQLite persistence.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class) => match ($class) { Taxon::class => $taxons, Channel::class => $channels, default => $real->getRepository($class) });
        foreach (['find', 'persist', 'flush', 'contains', 'remove'] as $method) $em->method($method)->willReturnCallback($real->$method(...));
        $em->method('wrapInTransaction')->willReturnCallback($real->wrapInTransaction(...));
        $validator = new SiteDocumentValidator(); $references = new SiteMediaReferences($em);
        $pages = new SiteManagementService($em, $validator, new SiteLinkResolver($em, $validator), $references);
        $media = new SiteMediaService($em, new ImageUploadValidator(), $validator, $this->createStub(ImageUploaderInterface::class));
        $import = new SiteLegacyImportService($em, $pages, $validator, $references, $media);
        $existing = $pages->create(['title' => 'Mentions rédigées', 'slug' => 'nos-mentions', 'role' => 'mentions']);
        // An unrelated address is preserved, importer chooses another slug.
        $free = $pages->create(['title' => 'Page libre', 'slug' => 'bienvenue']);
        self::assertCount(2, $import->import()['created']);
        $home = $pages->rolePage('home'); $terms = $pages->rolePage('terms');
        self::assertSame('bienvenue-2', $home->getSlug());
        self::assertNull($home->getPublished());
        self::assertSame('Accueil confidentiel', $home->getDraft()['document']['blocks'][0]['props']['title']);
        self::assertSame('Accueil public', $home->getLegacyPublished()['document']['blocks'][0]['props']['title']);
        self::assertStringContainsString('Conditions publiques', json_encode($terms->getLegacyPublished()));
        self::assertStringNotContainsString('confidentielles', json_encode($terms->getLegacyPublished()));
        $before = array_map(static fn ($p) => [$p->getId(), $p->getDraft(), $p->getLegacyPublished()], $pages->pages());
        self::assertSame([], $import->import()['created']);
        $real->clear();
        self::assertSame($before, array_map(static fn ($p) => [$p->getId(), $p->getDraft(), $p->getLegacyPublished()], $pages->pages()));
        self::assertSame('Mentions rédigées', $pages->page($existing->getId())->getDraft()['title']);
        self::assertSame('Page libre', $pages->page($free->getId())->getDraft()['title']);
        self::assertSame($raw, $taxon->getTranslation('en_US')->getDescription());
        self::assertFalse($pages->isSitePublished());
        $blocks = $pages->rolePage('home')->getDraft()['document']['blocks'];
        self::assertContains('catalog', array_column($blocks, 'type'));
        self::assertContains('giftCard', array_column($blocks, 'type'));
        self::assertSame('beneficiary', $blocks[array_key_last($blocks)]['props']['link']['target']);
        $home = $pages->rolePage('home');
        $pages->restore($home);
        self::assertSame($home->getLegacyPublished(), $home->getDraft());
        self::assertNull($home->getPublished());
        self::assertSame($raw, $taxon->getTranslation('en_US')->getDescription());
    }
}
