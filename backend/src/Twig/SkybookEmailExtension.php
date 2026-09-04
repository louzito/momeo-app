<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Taxonomy\Taxon;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Textes des emails transactionnels EDITABLES PAR CENTRE (espace centre >
 * Configuration boutique > Emails). Les gabarits Sylius surcharges
 * (templates/bundles/...) appellent skybook_email_text(type, champ, vars) :
 * texte personnalise du centre si renseigne, sinon defaut ci-dessous — avec
 * remplacement des variables %prenom%, %commande%, %centre%, %total%, etc.
 *
 * Multi-centres : la config est lue dans le taxon skybook_config de la BDD DU
 * TENANT COURANT (les emails partent en sync pendant la requete du centre,
 * la connexion Doctrine est donc deja la bonne). Si messenger passe en async
 * un jour, il faudra transporter le slug dans le message.
 */
final class SkybookEmailExtension extends AbstractExtension
{
    private const DEFAULTS = [
        'booking_confirmation' => [
            'subject' => 'Confirmation de votre réservation %reservation% — %etablissement%',
            'intro' => "Bonjour %prenom%,\n\nVotre réservation pour %prestation% est bien enregistrée le %date% à %heure%.",
            'signature' => "À bientôt,\nL'équipe %etablissement%",
        ],
        'payment_confirmation' => [
            'subject' => 'Confirmation de votre paiement — %etablissement%',
            'intro' => "Bonjour %prenom%,\n\nNous confirmons votre paiement de %total% pour la réservation %reservation%.",
            'signature' => "Merci de votre confiance,\nL'équipe %etablissement%",
        ],
        'booking_cancelled' => [
            'subject' => 'Annulation de votre réservation %reservation% — %etablissement%',
            'intro' => "Bonjour %prenom%,\n\nVotre réservation pour %prestation%, prévue le %date% à %heure%, a bien été annulée.",
            'signature' => "L'équipe %etablissement%",
        ],
        'booking_rescheduled' => [
            'subject' => 'Nouvelle date pour votre réservation %reservation% — %etablissement%',
            'intro' => "Bonjour %prenom%,\n\nVotre réservation pour %prestation% a été déplacée au %date% à %heure%.",
            'signature' => "À bientôt,\nL'équipe %etablissement%",
        ],
        'booking_reminder' => [
            'subject' => 'Rappel de votre rendez-vous %reservation% — %etablissement%',
            'intro' => "Bonjour %prenom%,\n\nNous vous rappelons votre rendez-vous pour %prestation%, prévu le %date% à %heure%.",
            'signature' => "À bientôt,\nL'équipe %etablissement%",
        ],
        'order_confirmation' => [
            'subject' => 'Votre commande %commande% — %etablissement%',
            'intro' => "Bonjour %prenom%,\n\nMerci pour votre réservation chez %etablissement% !\nVotre commande %commande% (%total%) a bien été enregistrée.",
            'signature' => "À bientôt,\nL'équipe %etablissement%",
        ],
        'invoice_generated' => [
            'subject' => 'Votre facture %facture% — %etablissement%',
            'intro' => "Bonjour %prenom%,\n\nVotre facture %facture% pour la commande %commande% est disponible en pièce jointe de cet email.",
            'signature' => "Merci de votre confiance,\nL'équipe %etablissement%",
        ],
        'gift_voucher' => [
            'subject' => 'Votre chèque cadeau %prestation% — %etablissement%',
            'intro' => "Bonjour,\n\nVoici le chèque cadeau %prestation% offert par un proche chez %etablissement% !\n%prenom_beneficiaire% pourra choisir sa date directement depuis l'espace bénéficiaire, avec le code ci-dessous ou en scannant le QR code.",
            'signature' => "À bientôt,\nL'équipe %etablissement%",
        ],
    ];

    /** @var array<string, mixed>|null */
    private ?array $configCache = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('skybook_email_text', $this->emailText(...)),
        ];
    }

    /** @param array<string, string> $vars */
    public function emailText(string $type, string $field, array $vars = []): string
    {
        $custom = $this->config()['emails'][$type][$field] ?? null;
        $text = \is_string($custom) && trim($custom) !== ''
            ? $custom
            : (self::DEFAULTS[$type][$field] ?? '');

        return strtr($text, $vars);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        if ($this->configCache !== null) {
            return $this->configCache;
        }
        try {
            $taxon = $this->em->getRepository(Taxon::class)->findOneBy(['code' => 'skybook_config']);
            $data = json_decode($taxon?->getTranslation('en_US')?->getDescription() ?: '{}', true);

            return $this->configCache = \is_array($data) ? $data : [];
        } catch (\Throwable) {
            return $this->configCache = [];
        }
    }
}
