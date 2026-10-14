<?php

declare(strict_types=1);

namespace App\Tests\Email;

use App\Service\Email\EmailRichText;
use PHPUnit\Framework\TestCase;

final class EmailRichTextTest extends TestCase
{
    public function testFormattingEscapesTextAndSubstitutedVariables(): void
    {
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bonjour %prenom% <script>', 'marks' => [['type' => 'bold']]]]]]];
        self::assertSame('<p><strong>Bonjour &lt;img&gt; &lt;script&gt;</strong></p>', EmailRichText::render($doc, ['%prenom%' => '<img>']));
    }

    public function testUnsafeLinksCannotBecomeExecutable(): void
    {
        self::assertSame('Lien', EmailRichText::render(['type' => 'text', 'text' => 'Lien', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]));
    }

    public function testUnsupportedNodesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EmailRichText::render(['type' => 'script']);
    }
}
