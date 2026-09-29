<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Entity\Product\Product;
use App\Entity\SitePage;
use App\Service\Site\{SiteDocumentValidator, SiteLinkResolver, SiteManagementService, SiteMediaReferences};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class SiteConnectedSectionsTest extends TestCase
{
    private function document(array $props = []): array
    {
        return ['title' => 'Nos offres', 'slug' => 'nos-offres', 'seo' => ['title' => '', 'description' => ''], 'document' => ['schemaVersion' => 1, 'blocks' => [
            ['id' => 'catalog', 'type' => 'catalog', 'props' => $props + ['title' => '', 'mode' => 'selection', 'category' => 'prestations', 'codes' => ['massage'], 'limit' => 3, 'buttonLabel' => 'Découvrir']],
            ['id' => 'gift', 'type' => 'giftCard', 'props' => ['title' => '', 'text' => 'Un cadeau', 'image' => null, 'buttonLabel' => 'Offrir']],
        ]]];
    }
    public function testOnlyReferencesAndBoundedPresentationCanBeSaved(): void
    {
        $validator = new SiteDocumentValidator();
        self::assertSame($this->document(), $validator->page($this->document()));
        foreach ([['price' => 10], ['limit' => 0], ['limit' => 13], ['limit' => '3'], ['codes' => ['massage', 'massage']], ['codes' => [['tenant' => 'other']]], ['category' => 'other'], ['buttonLabel' => '<script>']] as $invalid) {
            try { $validator->page($this->document($invalid)); self::fail('Invalid catalog accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testForeignReferenceCannotBeSavedOrPublished(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $products = $this->createMock(EntityRepository::class);
        $products->expects(self::exactly(2))->method('findOneBy')->with(['code' => 'massage'])->willReturn(null);
        $pages = $this->createStub(EntityRepository::class);
        $pages->method('findBy')->willReturn([]);
        $em->method('getRepository')->willReturnCallback(static fn (string $class) => $class === Product::class ? $products : $pages);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $work) => $work());
        $em->expects(self::never())->method('flush');
        $validator = new SiteDocumentValidator();
        $service = new SiteManagementService($em, $validator, new SiteLinkResolver($em, $validator), new SiteMediaReferences($em));
        $page = new SitePage($this->document());
        $em->method('find')->willReturn($page);
        foreach ([fn () => $service->update($page, $this->document()), fn () => $service->publish($page)] as $operation) {
            try { $operation(); self::fail('Foreign reference accepted'); }
            catch (\InvalidArgumentException) { self::assertNull($page->getPublished()); }
        }
    }
    public function testGiftImagesParticipateInTenantValidationAndUsageTracking(): void
    {
        $document = $this->document();
        $document['document']['blocks'][1]['props']['image'] = ['mediaId' => str_repeat('a', 32), 'alt' => 'Cadeau'];
        self::assertSame([str_repeat('a', 32)], SiteMediaReferences::ids($document));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $this->expectException(\InvalidArgumentException::class);
        (new SiteMediaReferences($em))->validate($document);
    }
}
