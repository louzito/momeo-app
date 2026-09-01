<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GiftVoucherRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * SkyBook — Cheque cadeau (chantier "cheques cadeaux reels", 2026-08).
 *
 * Table PAR TENANT (une ligne par cheque, dans la BDD du centre qui l'a
 * vendu) : isolation automatique via TenantConnectionMiddleware (bascule de
 * connexion par requete/CLI), aucun champ "tenant" necessaire ici.
 *
 * Cycle de vie du statut STOCKE :
 *   awaiting_payment -> active -> used
 * `expired` n'est JAMAIS ecrit en base : calcule a la lecture
 * (getEffectiveStatus(), pas de cron) en comparant expiresAt a maintenant,
 * et UNIQUEMENT si le statut stocke est encore `active` (un cheque `used`
 * reste `used` meme au-dela de sa date d'expiration).
 *
 * Code : 10 chiffres, genere serveur — aleatoire non sequentiel, unicite
 * verifiee en base (voir App\GiftVoucher\GiftVoucherCodeGenerator). Le front
 * l'affiche groupe (123 456 7890) ; stocke sans espaces.
 *
 * jumpTypeCode/jumpTypeName sont DENORMALISES au moment de l'achat : le
 * produit source peut etre renomme/desactive/supprime sans casser un cheque
 * deja vendu.
 */
#[ORM\Entity(repositoryClass: GiftVoucherRepository::class)]
#[ORM\Table(name: 'skybook_gift_voucher')]
#[ORM\Index(columns: ['status'], name: 'idx_skybook_gift_voucher_status')]
#[ORM\Index(columns: ['purchaser_email'], name: 'idx_skybook_gift_voucher_purchaser_email')]
#[ORM\Index(columns: ['beneficiary_email'], name: 'idx_skybook_gift_voucher_beneficiary_email')]
class GiftVoucher
{
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_USED = 'used';
    /** Jamais stocke : uniquement retourne par getEffectiveStatus(). */
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'code', type: 'string', length: 10, unique: true)]
    private string $code;

    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status = self::STATUS_AWAITING_PAYMENT;

    #[ORM\Column(name: 'jump_type_code', type: 'string', length: 255)]
    private string $jumpTypeCode;

    #[ORM\Column(name: 'jump_type_name', type: 'string', length: 255)]
    private string $jumpTypeName;

    /** Montant en centimes (comme les prix Sylius). */
    #[ORM\Column(name: 'amount', type: 'integer')]
    private int $amount;

    #[ORM\Column(name: 'currency_code', type: 'string', length: 3)]
    private string $currencyCode;

    #[ORM\Column(name: 'purchaser_name', type: 'string', length: 255)]
    private string $purchaserName;

    #[ORM\Column(name: 'purchaser_email', type: 'string', length: 255)]
    private string $purchaserEmail;

    #[ORM\Column(name: 'beneficiary_name', type: 'string', length: 255, nullable: true)]
    private ?string $beneficiaryName = null;

    #[ORM\Column(name: 'beneficiary_email', type: 'string', length: 255)]
    private string $beneficiaryEmail;

    #[ORM\Column(name: 'personal_message', type: 'text', nullable: true)]
    private ?string $personalMessage = null;

    /** Numero de la commande d'ACHAT (checkoutState=completed du tunnel cadeau). */
    #[ORM\Column(name: 'purchase_order_number', type: 'string', length: 255)]
    private string $purchaseOrderNumber;

    /** Numero de la commande d'UTILISATION (saut reserve par le beneficiaire), nul tant que non utilise. */
    #[ORM\Column(name: 'usage_order_number', type: 'string', length: 255, nullable: true)]
    private ?string $usageOrderNumber = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Date de passage en `active` (encaissement du paiement de la commande d'achat). */
    #[ORM\Column(name: 'activated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    /** Date de passage en `used` (reservation du beneficiaire). */
    #[ORM\Column(name: 'used_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    /** Statut STOCKE brut (voir getEffectiveStatus() pour l'expiration calculee). */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /** Statut a afficher : `expired` calcule a la volee si actif et perime. */
    public function getEffectiveStatus(): string
    {
        if ($this->status === self::STATUS_ACTIVE && $this->expiresAt < new \DateTimeImmutable()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    public function isUsable(): bool
    {
        return $this->getEffectiveStatus() === self::STATUS_ACTIVE;
    }

    public function getServiceCode(): string
    {
        return $this->jumpTypeCode;
    }

    public function setServiceCode(string $serviceCode): void
    {
        $this->jumpTypeCode = $serviceCode;
    }

    public function getServiceName(): string
    {
        return $this->jumpTypeName;
    }

    public function setServiceName(string $serviceName): void
    {
        $this->jumpTypeName = $serviceName;
    }

    /** @deprecated Utiliser getServiceCode(). */
    public function getJumpTypeCode(): string
    {
        return $this->getServiceCode();
    }

    /** @deprecated Utiliser setServiceCode(). */
    public function setJumpTypeCode(string $jumpTypeCode): void
    {
        $this->setServiceCode($jumpTypeCode);
    }

    /** @deprecated Utiliser getServiceName(). */
    public function getJumpTypeName(): string
    {
        return $this->getServiceName();
    }

    /** @deprecated Utiliser setServiceName(). */
    public function setJumpTypeName(string $jumpTypeName): void
    {
        $this->setServiceName($jumpTypeName);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    public function setCurrencyCode(string $currencyCode): void
    {
        $this->currencyCode = $currencyCode;
    }

    public function getPurchaserName(): string
    {
        return $this->purchaserName;
    }

    public function setPurchaserName(string $purchaserName): void
    {
        $this->purchaserName = $purchaserName;
    }

    public function getPurchaserEmail(): string
    {
        return $this->purchaserEmail;
    }

    public function setPurchaserEmail(string $purchaserEmail): void
    {
        $this->purchaserEmail = $purchaserEmail;
    }

    public function getBeneficiaryName(): ?string
    {
        return $this->beneficiaryName;
    }

    public function setBeneficiaryName(?string $beneficiaryName): void
    {
        $this->beneficiaryName = $beneficiaryName;
    }

    public function getBeneficiaryEmail(): string
    {
        return $this->beneficiaryEmail;
    }

    public function setBeneficiaryEmail(string $beneficiaryEmail): void
    {
        $this->beneficiaryEmail = $beneficiaryEmail;
    }

    public function getPersonalMessage(): ?string
    {
        return $this->personalMessage;
    }

    public function setPersonalMessage(?string $personalMessage): void
    {
        $this->personalMessage = $personalMessage;
    }

    public function getPurchaseOrderNumber(): string
    {
        return $this->purchaseOrderNumber;
    }

    public function setPurchaseOrderNumber(string $purchaseOrderNumber): void
    {
        $this->purchaseOrderNumber = $purchaseOrderNumber;
    }

    public function getUsageOrderNumber(): ?string
    {
        return $this->usageOrderNumber;
    }

    public function setUsageOrderNumber(?string $usageOrderNumber): void
    {
        $this->usageOrderNumber = $usageOrderNumber;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): void
    {
        $this->activatedAt = $activatedAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function setUsedAt(?\DateTimeImmutable $usedAt): void
    {
        $this->usedAt = $usedAt;
    }
}
