<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User\AdminUser;
use App\Entity\Channel\Channel;
use App\Twig\SkybookEmailExtension;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_API_ACCESS')]
final class AdminEmailTemplateApiController
{
    #[Route('/api/v2/admin/email-templates/test', methods: ['POST'])]
    public function test(Request $request, Security $security, SenderInterface $sender, SkybookEmailExtension $emails, EntityManagerInterface $em): JsonResponse
    {
        $admin = $security->getUser();
        if (!$admin instanceof AdminUser) return new JsonResponse(['message' => 'Session administrateur requise.'], 401);
        $recipient = $admin->getEmail();
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) return new JsonResponse(['message' => 'Le compte connecté ne possède pas une adresse email valide.'], 422);
        try {
            if (strlen($request->getContent()) > 100000) throw new \InvalidArgumentException('Modèle trop volumineux.');
            $data = $request->toArray();
            $type = $data['type'] ?? '';
            $template = $data['template'] ?? null;
            if (!is_string($type) || !is_array($template)) throw new \InvalidArgumentException('Modèle invalide.');
            $channel = $em->getRepository(Channel::class)->findOneBy([]);
            $vars = ['%prenom%' => 'Camille', '%nom%' => 'Exemple', '%prenom_beneficiaire%' => 'Camille', '%reservation%' => 'RDV-TEST', '%commande%' => 'CMD-TEST', '%facture%' => 'FAC-TEST', '%prestation%' => 'Prestation de démonstration', '%date%' => '15/10/2026', '%heure%' => '14:00', '%centre%' => $channel?->getName() ?? 'TodaTempo', '%etablissement%' => $channel?->getName() ?? 'TodaTempo', '%total%' => '100,00 EUR', '%montant%' => '100,00 EUR', '%code%' => 'CADEAU-TEST', '%expire%' => '15/10/2027'];
            $subject = $emails->testSubject($type, $template, $vars);
            $intro = $emails->emailHtml($type, 'intro', $vars, $template);
            $signature = $emails->emailHtml($type, 'signature', $vars, $template);
        } catch (\InvalidArgumentException|\Symfony\Component\HttpFoundation\Exception\JsonException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }
        try {
            $sender->send('email_template_test', [$recipient], compact('subject', 'intro', 'signature', 'channel'));
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'L’envoi du test a échoué. Réessayez plus tard.'], 502);
        }
        return new JsonResponse(['recipient' => $recipient]);
    }
}
