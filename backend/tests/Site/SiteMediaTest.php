<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Controller\{AdminSiteMediaApiController, ShopSiteApiController};
use App\Entity\{SiteMedia, SiteMediaUsage, SitePage};
use App\Service\Security\ImageUploadValidator;
use App\Service\Site\{SiteMediaReferences, SiteDocumentValidator, SiteLinkResolver, SiteManagementService, SiteMediaService};
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SiteMediaTest extends TestCase
{
    private array $temporary = [];
    protected function tearDown(): void { foreach ($this->temporary as $path) @unlink($path); }
    private function tenant(?ImageUploaderInterface $uploader = null): array
    {
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true));
        $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        (new SchemaTool($em))->createSchema(array_map($em->getClassMetadata(...), [SitePage::class, SiteMedia::class, SiteMediaUsage::class]));
        $validator = new SiteDocumentValidator();
        $links = new SiteLinkResolver($em, $validator);
        return [$em, new SiteManagementService($em, $validator, $links, new SiteMediaReferences($em)), new SiteMediaService($em, new ImageUploadValidator(), $validator, $uploader ?? $this->createStub(ImageUploaderInterface::class)), $links];
    }
    private function media(EntityManager $em): SiteMedia
    {
        $image = new SiteMedia('tenant/image.webp', 'tenant/thumb.webp', 800, 400, 'Un paysage');
        $em->persist($image); $em->flush();
        return $image;
    }
    private function draft(SitePage $page, SiteMedia $image, string $type = 'image'): array
    {
        $draft = $page->getDraft();
        $props = ['mediaId' => $image->getId(), 'alt' => 'Paysage au soleil'];
        $draft['document']['blocks'] = [['id' => 'photo', 'type' => $type, 'props' => $type === 'gallery' ? ['images' => [$props, $props]] : $props]];
        return $draft;
    }
    public function testReuseDraftPublicationRestoreAndArchivedPageProtectImage(): void
    {
        [$em, $pages, $media, $links] = $this->tenant();
        $image = $this->media($em);
        $page = $pages->create(['title' => 'Photos', 'slug' => 'photos']);
        $pages->update($page, $this->draft($page, $image, 'gallery'));
        self::assertTrue($media->used($image));
        self::assertCount(1, $media->all());
        $pages->publishBatch([$page->getId()], []);
        $public = json_decode((new ShopSiteApiController($pages, $links))->page('photos')->getContent(), true);
        self::assertSame('Paysage au soleil', $public['document']['blocks'][0]['props']['images'][0]['alt']);
        self::assertSame(800, $public['media'][$image->getId()]['width']);
        $copy = $pages->duplicate($page, ['title' => 'Copie', 'slug' => 'copie']);
        self::assertSame(2, $em->getRepository(SiteMediaUsage::class)->count([]));
        $draft = $page->getDraft(); $draft['document']['blocks'] = [];
        $pages->update($page, $draft);
        $controller = new AdminSiteMediaApiController($media, new SiteDocumentValidator());
        self::assertSame(409, $controller->delete($image->getId())->getStatusCode());
        $pages->restore($page);
        self::assertCount(1, $page->getDraft()['document']['blocks']);
        $pages->archive($copy);
        $pages->update($page, $draft); $pages->publish($page);
        self::assertSame(409, $controller->delete($image->getId())->getStatusCode()); // archived copy still restorable data
        self::assertSame(1, $em->getRepository(SiteMediaUsage::class)->count([]));
    }
    public function testLastPublishedUseMustBeRemovedBeforeDeleting(): void
    {
        [$em, $pages, $media] = $this->tenant();
        $image = $this->media($em);
        $page = $pages->create(['title' => 'Photo', 'slug' => 'photo']);
        $pages->update($page, $this->draft($page, $image, 'banner')); $pages->publish($page);
        $draft = $page->getDraft(); $draft['document']['blocks'] = [];
        $pages->update($page, $draft);
        self::assertTrue($media->used($image));
        $pages->publish($page);
        self::assertFalse($media->used($image));
        $id = $image->getId(); $media->delete($image);
        self::assertNull($media->find($id));
    }
    public function testCrossTenantReferencesAndDeletionAreRejected(): void
    {
        [$a] = $this->tenant();
        [$b, $pages, $media] = $this->tenant();
        $image = $this->media($a);
        self::assertSame([], $media->all()); self::assertNull($media->find($image->getId()));
        $page = $pages->create(['title' => 'Photo', 'slug' => 'photo']);
        try { $pages->update($page, $this->draft($page, $image)); self::fail(); }
        catch (\InvalidArgumentException) { self::assertSame([], $page->getDraft()['document']['blocks']); }
        $this->expectException(NotFoundHttpException::class);
        (new AdminSiteMediaApiController($media, new SiteDocumentValidator()))->delete($image->getId());
    }
    public function testForeignKeyProtectsConcurrentDeletionEvenWithoutServiceCheck(): void
    {
        [$em, $pages] = $this->tenant();
        $image = $this->media($em); $page = $pages->create(['title' => 'Photo', 'slug' => 'photo']);
        $pages->update($page, $this->draft($page, $image));
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $em->getConnection()->executeStatement('DELETE FROM todatempo_site_media WHERE id = ?', [$image->getId()]);
    }
    private function file(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'media-test-'); $this->temporary[] = $path; file_put_contents($path, $bytes);
        return new UploadedFile($path, $name, null, null, true);
    }
    public function testUploadReencodesAndResizesUsingExistingUploader(): void
    {
        $stored = [];
        $uploader = $this->createMock(ImageUploaderInterface::class);
        $uploader->expects(self::exactly(2))->method('upload')->willReturnCallback(function ($image) use (&$stored): void {
            $stored[] = getimagesize($image->getFile()->getPathname());
            $image->setPath('tenant/'.count($stored).'.webp');
        });
        [, , $media] = $this->tenant($uploader);
        $source = imagecreatetruecolor(2000, 1000); ob_start(); imagepng($source); $bytes = ob_get_clean(); unset($source);
        $result = $media->upload($this->file($bytes, 'photo.png'), 'Mon image')->toArray();
        self::assertSame([1600, 800, IMAGETYPE_WEBP], array_slice($stored[0], 0, 3));
        self::assertSame([400, 200, IMAGETYPE_WEBP], array_slice($stored[1], 0, 3));
        self::assertSame(1600, $result['width']); self::assertSame('Mon image', $result['alt']);
    }
    public function testInvalidTypesBytesAndDimensionsNeverReachStorage(): void
    {
        $uploader = $this->createMock(ImageUploaderInterface::class); $uploader->expects(self::never())->method('upload');
        [, , $media] = $this->tenant($uploader);
        $source = imagecreatetruecolor(6001, 1); ob_start(); imagepng($source); $large = ob_get_clean(); unset($source);
        foreach ([['<svg onload="alert(1)"></svg>', 'test.svg'], ['not a photo', 'fake.jpg'], [str_repeat('a', 5242881), 'heavy.png'], [$large, 'wide.png']] as [$bytes, $name]) {
            try { $media->upload($this->file($bytes, $name), ''); self::fail($name.' should be rejected'); }
            catch (\InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
        self::assertSame([], $media->all());
    }
}
