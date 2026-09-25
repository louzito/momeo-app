<?php

declare(strict_types=1);

namespace App\Service\Payment;

/** Échec technique distant ou local ; ne signifie pas que le fournisseur a annulé. */
final class RefundFailed extends \RuntimeException
{
}
