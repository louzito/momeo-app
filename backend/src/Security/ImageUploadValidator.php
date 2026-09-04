<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Server-side guard applied before Sylius moves administrator image uploads. */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 48)]
final class ImageUploadValidator
{
    private const MAX_BYTES = 5_242_880;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isAdminImageRequest($request)) {
            return;
        }

        foreach ($request->files->all() as $value) {
            foreach ($this->uploads($value) as $upload) {
                $error = $this->validate($upload);
                if ($error !== null) {
                    $event->setResponse(new JsonResponse(['error' => 'invalid_image', 'message' => $error], 422, ['Cache-Control' => 'no-store']));
                    return;
                }
            }
        }
    }

    private function isAdminImageRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/v2/admin/') && $request->files->all() !== [];
    }

    /** @return iterable<UploadedFile> */
    private function uploads(mixed $value): iterable
    {
        if ($value instanceof UploadedFile) {
            yield $value;
        } elseif (\is_array($value)) {
            foreach ($value as $nested) {
                yield from $this->uploads($nested);
            }
        }
    }

    private function validate(UploadedFile $upload): ?string
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
