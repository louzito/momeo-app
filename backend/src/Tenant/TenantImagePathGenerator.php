<?php

declare(strict_types=1);

namespace App\Tenant;

use Sylius\Component\Core\Generator\ImagePathGeneratorInterface;
use Sylius\Component\Core\Model\ImageInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Medias par tenant, version "prefixe de chemin" : les uploads des tenants
 * non-defaut recoivent un chemin `{slug}/xx/yy/hash.ext` -> fichiers stockes
 * sous public/media/image/{slug}/... via le storage flysystem STANDARD
 * (racine partagee). Avantage decisif sur un root flysystem par tenant :
 * liip_imagine (URLs /media/cache/resolve/...) et les URLs renvoyees par
 * l'API fonctionnent telles quelles, le chemin contenant deja le slug — et le
 * cache liip est naturellement isole par slug (media/cache/<filtre>/{slug}/...).
 * Le tenant par defaut (skyline) garde ses chemins historiques sans prefixe.
 */
#[AsDecorator('sylius.generator.image_path')]
final class TenantImagePathGenerator implements ImagePathGeneratorInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly ImagePathGeneratorInterface $inner,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function generate(ImageInterface $image): string
    {
        $path = $this->inner->generate($image);

        return $this->tenantContext->isDefaultTenant() ? $path : $this->tenantContext->getSlug() . '/' . $path;
    }
}
