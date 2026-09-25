<?php

declare(strict_types=1);

namespace App\Service\Tenant;

/** Expected SSO rejection, translated to a 401 by the HTTP adapter. */
final class AdminSsoRejected extends \RuntimeException
{
}
