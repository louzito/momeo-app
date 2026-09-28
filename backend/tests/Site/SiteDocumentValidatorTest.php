<?php

declare(strict_types=1);

namespace App\Tests\Site;

use App\Service\Site\SiteDocumentValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteDocumentValidatorTest extends TestCase
{
    private static function page(array $blocks = []): array { return ['title' => 'Bonjour', 'slug' => 'bonjour', 'document' => ['schemaVersion' => 1, 'blocks' => $blocks], 'seo' => ['title' => '', 'description' => '']]; }
    public static function invalidDocuments(): iterable
    {
        foreach (SiteDocumentValidator::RESERVED as $slug) yield 'reserved '.$slug => [array_replace(self::page(), ['slug' => $slug])];
        foreach (['html', 'image', 'script', 'unknown'] as $type) yield $type => [self::page([['id' => 'one', 'type' => $type, 'props' => []]])];
        yield 'property' => [self::page([['id' => 'one', 'type' => 'heading', 'props' => ['text' => 'Bonjour', 'level' => 2, 'css' => 'display:none']]])];
        yield 'html title' => [array_replace(self::page(), ['title' => '<script>alert(1)</script>'])];
        yield 'null rich content' => [self::page([['id' => 'one', 'type' => 'text', 'props' => ['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => null]]]]]])];
        yield 'future version' => [array_replace(self::page(), ['document' => ['schemaVersion' => 2, 'blocks' => []]])];
        yield 'unknown metadata' => [array_replace(self::page(), ['seo' => ['title' => '', 'description' => '', 'script' => 'alert(1)']])];
        yield 'arbitrary rich text' => [self::page([['id' => 'one', 'type' => 'text', 'props' => ['content' => ['type' => 'doc', 'content' => [['type' => 'html', 'content' => []]]]]]])];
        yield 'nested properties' => [self::page([['id' => 'one', 'type' => 'text', 'props' => ['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['style' => 'color:red']]]]]]])];
    }
    #[DataProvider('invalidDocuments')]
    public function testClosedSchemaRejectsInvalidDocuments(array $page): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SiteDocumentValidator())->page($page);
    }
    public static function unsafeLinks(): iterable
    {
        foreach (['javascript:alert(1)', 'data:text/html,hello', '//example.com', '/admin', 'https://user:pass@example.com', "https://example.com/\nfoo", 'https://example.com/\\evil', '/autre-etablissement/contact'] as $url) yield $url => [['type' => 'external', 'target' => $url]];
        yield 'route' => [['type' => 'route', 'target' => 'admin']];
        yield 'extra key' => [['type' => 'route', 'target' => 'shop', 'onclick' => 'alert(1)']];
    }
    #[DataProvider('unsafeLinks')]
    public function testUnsafeLinksAreRefused(array $link): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SiteDocumentValidator())->link($link);
    }
    public function testOnlyOneSubmenuLevelIsAllowed(): void
    {
        $item = ['label' => 'Boutique', 'link' => ['type' => 'route', 'target' => 'shop']];
        $this->expectException(\InvalidArgumentException::class);
        (new SiteDocumentValidator())->menu([$item + ['children' => [$item + ['children' => [$item]]]]]);
    }
    public function testHiddenMustBeBoolean(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SiteDocumentValidator())->menu([['label' => 'Lien', 'link' => ['type' => 'route', 'target' => 'services'], 'hidden' => 'false']]);
    }
    public function testBookingDestinationDoesNotOpenGlobalCalendar(): void
    {
        self::assertSame('shop?categorie=prestations', SiteDocumentValidator::ROUTES['booking']);
    }
    public function testLimitedTiptapIsPreserved(): void
    {
        $page = self::page([['id' => 'intro', 'type' => 'text', 'props' => ['content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bonjour & bienvenue', 'marks' => [['type' => 'bold']]]]]]]]]]);
        self::assertSame($page, (new SiteDocumentValidator())->page($page));
    }
}
