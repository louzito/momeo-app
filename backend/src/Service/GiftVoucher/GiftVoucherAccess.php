<?php

declare(strict_types=1);

namespace App\Service\GiftVoucher;

use App\Entity\GiftVoucher;
use App\Repository\GiftVoucherRepository;

/** Lectures dans la connexion du tenant courant. Consommation exclusivement via BookingCreationService. */
final class GiftVoucherAccess
{
    public function __construct(
        private readonly GiftVoucherRepository $repository,
        private readonly GiftVoucherView $view,
        private readonly GiftVoucherQrCodeGenerator $qrCodeGenerator,
    ) {
    }

    public function findByCode(string $code): ?GiftVoucher
    {
        return $this->repository->findOneByCode($code);
    }

    public function findByOrderNumber(string $orderNumber): ?GiftVoucher
    {
        return $this->repository->findOneByPurchaseOrderNumber($orderNumber);
    }

    /** La consultation reste permise après expiration/consommation ; aucun jeton supplémentaire. */
    public function login(string $code, string $email): ?array
    {
        $voucher = $this->findByCode($code);
        if ($voucher === null || 0 !== strcasecmp($voucher->getBeneficiaryEmail(), $email)) {
            return null;
        }
        $firstName = trim((string) strtok((string) $voucher->getBeneficiaryName(), ' '));

        return ['email' => $voucher->getBeneficiaryEmail(), 'firstName' => $firstName !== '' ? $firstName : null];
    }

    public function qr(string $code): string
    {
        return $this->qrCodeGenerator->generatePng($code);
    }

    public function project(GiftVoucher $voucher): array
    {
        return $this->view->shop($voucher);
    }

    public function byEmail(string $email): array
    {
        return array_map($this->view->shop(...), $this->repository->findByEmail(trim($email)));
    }

    public function adminIndex(string $status): array
    {
        $vouchers = $this->repository->findBy([], ['createdAt' => 'DESC']);
        if ('' !== $status) {
            $vouchers = array_values(array_filter(
                $vouchers,
                static fn (GiftVoucher $v): bool => $v->getEffectiveStatus() === $status,
            ));
        }

        return [
            'member' => array_map($this->view->admin(...), $vouchers),
            'stats' => $this->repository->countByEffectiveStatus(),
        ];
    }
}
