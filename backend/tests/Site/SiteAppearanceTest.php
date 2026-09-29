<?php

declare(strict_types=1);
namespace App\Tests\Site;

use App\Entity\{SiteMedia, SiteMenu, SitePage};
use App\Entity\Taxonomy\{Taxon, TaxonImage};
use App\Entity\Channel\Channel;
use App\Service\Site\{SiteAppearanceService, SiteTemplateService, SiteManagementService, SiteDocumentValidator, SiteLinkResolver, SiteMediaReferences};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;

final class SiteAppearanceTest extends TestCase
{
    private function setupTenant(array $config = []): array
    {
        $taxon = new Taxon(); $taxon->setCode('todatempo_config'); $taxon->setCurrentLocale('en_US'); $taxon->setFallbackLocale('en_US');
        $taxon->getTranslation('en_US')->setDescription(json_encode($config));
        $channel = new Channel(); $channel->setName('Institut réel'); $channel->setContactEmail('contact@example.org');
        $image = new TaxonImage(); $image->setType('logo'); $image->setPath('tenant/logo.png'); $taxon->addImage($image);
        $media = new SiteMedia('tenant/photo.webp', 'tenant/thumb.webp', 500, 400, 'Notre salle');
        $menu = new SiteMenu('main');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $work) => $work());
        $em->method('find')->willReturnCallback(static fn (string $class, $id) => $class === SiteMenu::class ? $menu : ($class === SiteMedia::class && $id === $media->getId() ? $media : null));
        $em->method('getRepository')->willReturnCallback(function (string $class) use ($taxon, $channel, $media) {
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('findOneBy')->willReturn($class === Taxon::class ? $taxon : ($class === Channel::class ? $channel : null));
            $repo->method('findBy')->willReturn($class === SiteMedia::class ? [$media] : []);
            return $repo;
        });
        $validator = new SiteDocumentValidator();
        $pages = new SiteManagementService($em, $validator, new SiteLinkResolver($em, $validator), new SiteMediaReferences($em));
        return [$em, $taxon, $media, $pages, new SiteAppearanceService($em, $pages, $validator)];
    }
    private function theme(SiteAppearanceService $service): array
    {
        return $service->state() + ['colors' => ['header' => '#ffffff', 'textHeader' => '#111111', 'footer' => '#111111', 'textFooter' => '#ffffff'], 'branding' => ['brandPalette' => 'violet', 'accent' => 'rose'], 'typography' => 'classic', 'logo' => 'tenant/logo.png'];
    }
    public function testTemplatesCreateEditableUnpublishedPagesUsingTenantFactsAndTrackedMedia(): void
    {
        [$em, , $media, $pages] = $this->setupTenant(['address' => ['street' => '12 rue du Centre', 'city' => 'Lyon'], 'home' => ['subtitle' => 'Texte existant']]);
        foreach (['home', 'presentation', 'contact', 'blank'] as $template) {
            $page = $pages->create(['title' => 'Ma page', 'slug' => 'page-'.$template, 'template' => $template]);
            self::assertNull($page->getPublished());
            $blocks = $page->getDraft()['document']['blocks'];
            if ($template === 'blank') { self::assertSame([], $blocks); continue; }
            self::assertStringContainsString('12 rue du Centre', json_encode($blocks));
            self::assertStringContainsString('contact@example.org', json_encode($blocks));
            if ($template !== 'contact') self::assertContains($media->getId(), SiteMediaReferences::ids($page->getDraft()));
            $draft = $page->getDraft(); $draft['title'] = 'Modifiée'; $pages->update($page, $draft);
            self::assertSame('Modifiée', $page->getDraft()['title']);
            self::assertNull($page->getPublished());
        }
        $this->expectException(\InvalidArgumentException::class);
        (new SiteTemplateService($em))->document('arbitrary');
    }
    public function testAppearancePreservesPublishedAndOperationalDataAndRejectsStaleWrites(): void
    {
        $published = ['name' => 'Ancien nom', 'colors' => ['header' => '#123456'], 'assets' => ['logo' => 'old.png']];
        $draft = $published + ['bookingRules' => ['minimumNoticeHours' => 12], 'legal' => ['terms' => ['content' => 'Conditions']]];
        [, $taxon, , , $service] = $this->setupTenant(['schemaVersion' => 1, 'draft' => $draft, 'published' => $published, 'revision' => 8]);
        $data = $this->theme($service); $service->save($data);
        $result = json_decode($taxon->getTranslation('en_US')->getDescription(), true);
        self::assertSame($published, $result['published']);
        self::assertSame($draft['bookingRules'], $result['draft']['bookingRules']);
        self::assertSame($draft['legal'], $result['draft']['legal']);
        self::assertSame('classic', $result['draft']['typography']);
        self::assertSame(8, $result['revision']);
        $this->expectException(\DomainException::class); $service->save($data);
    }
    public function testForeignLogoAndUnsafeStylesAreRejectedWithoutWriting(): void
    {
        [$em, $taxon, , , $service] = $this->setupTenant(['name' => 'Institut']);
        $original = $taxon->getTranslation('en_US')->getDescription();
        foreach ([['logo' => 'other/logo.png'], ['typography' => 'url(https://example.org)'], ['branding' => ['brandPalette' => 'custom', 'accent' => 'orange']], ['colors' => ['header' => '#ffffff', 'textHeader' => '#ffffff', 'footer' => '#000000', 'textFooter' => '#ffffff']], ['primaryLink' => ['type' => 'page', 'target' => str_repeat('a', 32)]]] as $invalid) {
            try { $service->save(array_replace($this->theme($service), $invalid)); self::fail('Unsafe appearance accepted'); }
            catch (\InvalidArgumentException) { self::assertSame($original, $taxon->getTranslation('en_US')->getDescription()); }
        }
    }
    public function testLegacyConfigurationBecomesDraftWithoutPublishingAndMenuEditKeepsButton(): void
    {
        [, $taxon, , $pages, $service] = $this->setupTenant(['name' => 'Institut']);
        $data = $this->theme($service); $data['primaryLink'] = ['type' => 'route', 'target' => 'booking'];
        $service->save($data);
        self::assertSame(['name' => 'Institut'], json_decode($taxon->getTranslation('en_US')->getDescription(), true)['published']);
        $pages->saveMenu('main', ['items' => []]);
        self::assertSame($data['primaryLink'], $pages->menu('main')->getPrimaryLink());
        self::assertNull($pages->menu('main')->getPublished());
    }
}
