<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\{SiteMedia, SiteMediaUsage};
use App\Service\Security\ImageUploadValidator;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\TaxonImage;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Symfony\Component\HttpFoundation\File\{File, UploadedFile};

final class SiteMediaService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageUploadValidator $uploads,
        private readonly SiteDocumentValidator $validator,
        private readonly ImageUploaderInterface $uploader,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')] private readonly string $projectDir = '',
    ) {}
    public function all(): array { return $this->em->getRepository(SiteMedia::class)->findAll(); }
    public function find(string $id): ?SiteMedia { return $this->em->find(SiteMedia::class, $id); }
    public function used(SiteMedia $media): bool { return $this->em->getRepository(SiteMediaUsage::class)->count(['media' => $media]) > 0; }
    public function describe(SiteMedia $media): array { return $media->toArray() + ['used' => $this->used($media)]; }
    public function update(SiteMedia $media, mixed $alt): void
    {
        $media->setAlt($this->validator->text($alt, 300, true));
        $this->em->flush();
    }
    public function upload(UploadedFile $file, mixed $alt): SiteMedia
    {
        $alt = $this->validator->text($alt, 300, true);
        if ($error = $this->uploads->validate($file)) throw new \InvalidArgumentException($error);
        $size = @getimagesize($file->getPathname());
        if (!$size || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new \InvalidArgumentException('Ce fichier ne contient pas une image lisible.');
        if ($size[0] < 1 || $size[1] < 1 || max($size[0], $size[1]) > 6000 || $size[0] * $size[1] > 20000000) throw new \InvalidArgumentException('Image trop grande : 6 000 pixels par côté et 20 millions de pixels maximum.');
        if (!function_exists('imagewebp')) throw new \RuntimeException('Traitement des images indisponible.');
        $source = @imagecreatefromstring(file_get_contents($file->getPathname()));
        if (!$source) throw new \InvalidArgumentException('Cette image est endommagée ou illisible.');
        $paths = [];
        try {
            [$path, $width, $height] = $this->store($source, 1600);
            $paths[] = $path;
            [$thumb] = $this->store($source, 400);
            $paths[] = $thumb;
            $media = new SiteMedia($path, $thumb, $width, $height, $alt);
            $this->em->persist($media);
            $this->em->flush();
            return $media;
        } catch (\Throwable $error) {
            foreach ($paths as $path) $this->uploader->remove($path);
            throw $error;
        } finally { unset($source); }
    }
    /** Caller only supplies paths of taxon images owned by the active tenant. Copy so
     * deleting the new media can never delete an asset still used by the old site. */
    public function importExisting(string $path, string $alt): SiteMedia
    {
        $root = realpath($this->projectDir.'/public/media/image');
        $source = $root ? realpath($root.'/'.$path) : false;
        if (!$source || !str_starts_with($source, $root.'/') || !is_file($source)) throw new \InvalidArgumentException('Une image du site existant est indisponible. Ajoutez-la à nouveau avant la reprise.');
        return $this->upload(new UploadedFile($source, basename($path), null, null, true), $alt);
    }
    private function store(\GdImage $source, int $bound): array
    {
        $scale = min(1, $bound / max(imagesx($source), imagesy($source)));
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        $temp = tempnam(sys_get_temp_dir(), 'site-image-');
        if ($temp === false) throw new \RuntimeException('Traitement des images indisponible.');
        try {
            if (!imagewebp($image, $temp, 82)) throw new \RuntimeException('Traitement des images indisponible.');
            $upload = new TaxonImage(); // Existing Sylius uploader + tenant path generator; no taxon is modified.
            $upload->setFile(new File($temp));
            try { $this->uploader->upload($upload); }
            catch (\Throwable $error) { if ($upload->getPath()) $this->uploader->remove($upload->getPath()); throw $error; }
            return [$upload->getPath(), $width, $height];
        } finally { unlink($temp); unset($image); }
    }
    public function delete(SiteMedia $media): void
    {
        if ($this->used($media)) throw new \DomainException('Cette image est utilisée dans une page, un brouillon ou une version publiée. Retirez-la de ces contenus avant de la supprimer.');
        // Commit the restrictive FK check before touching storage: a concurrent save cannot lose its file.
        $this->em->remove($media);
        $this->em->flush();
        $this->uploader->remove($media->getPath());
        $this->uploader->remove($media->getThumbnail());
    }
}
