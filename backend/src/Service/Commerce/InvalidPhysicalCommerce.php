<?php

declare(strict_types=1);

namespace App\Service\Commerce;

/** Entrée métier refusée ; les erreurs de persistance ne sont pas des erreurs 422. */
final class InvalidPhysicalCommerce extends \InvalidArgumentException
{
}
