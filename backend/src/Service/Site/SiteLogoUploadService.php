<?php

declare(strict_types=1);

namespace App\Service\Site;

use App\Entity\Taxonomy\{Taxon, TaxonImage};
use App\Service\Security\ImageUploadValidator;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Uploader\ImageUploaderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SiteLogoUploadService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ImageUploaderInterface $uploader, private readonly ImageUploadValidator $validator) {}

    public function upload(UploadedFile $file): array
    {
        if (($error = $this->validator->validate($file)) !== null) throw new \InvalidArgumentException($error);
        $taxon = $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'todatempo_config'])
            ?? $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config'])
            ?? throw new \InvalidArgumentException('Enregistrez d’abord les informations de votre établissement.');
        $image = new TaxonImage();
        $image->setType('draft_logo');
        $image->setFile($file);
        try {
            $this->uploader->upload($image);
            if (!$image->getPath()) throw new \RuntimeException('Le logo n’a pas pu être téléversé.');
            $this->em->wrapInTransaction(function () use ($taxon, $image): void {
                // Keep previous images: the published configuration may still reference them.
                $taxon->addImage($image);
                $this->em->persist($image);
                $this->em->flush();
            });
        } catch (\Throwable $error) {
            if ($image->getPath()) $this->uploader->remove($image->getPath());
            throw $error;
        }
        return ['path' => $image->getPath()];
    }
}
