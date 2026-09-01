<?php

declare(strict_types=1);

namespace App\GiftVoucher;

use App\Repository\GiftVoucherRepository;

/**
 * Genere le code a 10 chiffres d'un cheque cadeau : aleatoire (non
 * sequentiel, donc non devinable a partir d'un code existant) et unique en
 * base (verifie sur la BDD du tenant courant — la connexion Doctrine est deja
 * la bonne au moment de l'appel, voir App\Tenant\TenantConnectionMiddleware).
 */
final class GiftVoucherCodeGenerator
{
    private const MIN = 1_000_000_000;
    private const MAX = 9_999_999_999;

    public function __construct(private readonly GiftVoucherRepository $repository)
    {
    }

    public function generate(): string
    {
        do {
            $code = (string) random_int(self::MIN, self::MAX);
        } while ($this->repository->codeExists($code));

        return $code;
    }
}
