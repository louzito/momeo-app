<?php

declare(strict_types=1);

namespace App;

use App\Configuration\ProductionConfigurationValidator;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        if ($this->environment === 'prod' && !$this->booted) {
            (new ProductionConfigurationValidator())->validate($this->getProjectDir());
        }

        parent::boot();
    }
}
