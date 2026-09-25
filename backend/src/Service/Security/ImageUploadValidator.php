<?php

declare(strict_types=1);

namespace App\Service\Security;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Image constraints shared by upload entry points. */
final class ImageUploadValidator
{
    private const MAX_BYTES = 5_242_880;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function validate(UploadedFile $upload): ?string
    {
        $name = $upload->getClientOriginalName();
        if (!$upload->isValid() || $upload->getSize() === false || $upload->getSize() > self::MAX_BYTES) {
            return 'Image invalide ou superieure a 5 Mio.';
        }
        if ($name === '' || $name !== basename($name) || mb_strlen($name) > 128 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return 'Nom de fichier invalide.';
        }
        $mime = $upload->getMimeType();
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!\is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime]) || !\in_array($extension, self::MIME_EXTENSIONS[$mime], true)) {
            return 'Seules les images JPEG, PNG et WebP sont acceptees.';
        }

        return null;
    }
}
