<?php

declare(strict_types=1);

namespace App\Tests\GiftCard;

use App\Entity\GiftCard;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

final class GiftCardEmailTest extends TestCase
{
    public function testNamesMessageAndLinksAreEscaped(): void
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['@SyliusCore/Email/layout.html.twig' => '{% block content %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['autoescape' => 'html', 'strict_variables' => true]);
        $card = new GiftCard('demo', 'WEB', 'EUR', 5000, 'GC-TEST', new \DateTimeImmutable('+6 months'));
        $html = $twig->render('email/gift_card.html.twig', [
            'card' => $card, 'channel' => ['name' => 'Boutique & démo'],
            'purchase' => ['buyerName' => '<b>Camille</b>', 'recipientName' => 'Alex', 'message' => '<script>alert(1)</script> & bravo'],
            'shopUrl' => 'https://example.test/demo/shop',
            'documentUrl' => 'https://example.test/demo/gift-card/print#'.$card->getCode(),
        ]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>Camille</b>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('50,00 EUR', $html);
        self::assertStringContainsString('gift-card/print#'.$card->getCode(), $html);
        self::assertStringContainsString('Boutique &amp; démo', $html);
        // Les messages déjà en file avant cette évolution n’ont pas les nouveaux champs.
        $legacy = $twig->render('email/gift_card.html.twig', ['card' => $card, 'channel' => ['name' => 'Boutique']]);
        self::assertStringContainsString($card->getCode(), $legacy);
    }
}
