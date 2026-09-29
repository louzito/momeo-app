<?php

declare(strict_types=1);

namespace App\Service\Site;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final class SiteHtmlRenderer
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(TODATEMPO_SITE_NODE_BINARY)%')] private readonly string $nodeBinary = 'node',
    ) {}

    public function render(?array $page, array $metadata, string $base, string $fallback = ''): string
    {
        $front = $this->projectDir.'/../frontend';
        if (!is_readable($front.'/dist/index.html')) throw new \RuntimeException('Frontend build unavailable.');
        $template = file_get_contents($front.'/dist/index.html');
        if ($template === false) throw new \RuntimeException('Frontend build unavailable.');
        $content = $fallback;
        if ($page !== null) {
            $process = new Process([$this->nodeBinary, $front.'/dist-ssr/site-server.mjs']);
            $process->setInput(json_encode(['page' => $page, 'base' => $base], JSON_THROW_ON_ERROR));
            $process->setTimeout(5);
            $process->mustRun();
            $content = $process->getOutput();
        }
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $appBase = preg_replace('#/[^/]+/$#', '/', parse_url($base, PHP_URL_PATH));
        $head = '<meta name="todatempo-app-base" content="'.$escape($appBase).'"><title>'.$escape($metadata['title']).'</title>';
        foreach (['description' => $metadata['description'], 'robots' => $page ? 'index, follow' : 'noindex, nofollow'] as $name => $value) {
            $head .= '<meta name="'.$name.'" content="'.$escape($value).'">';
        }
        if ($page) {
            $head .= '<link rel="canonical" href="'.$escape($metadata['canonical']).'">';
            foreach (['og:type' => 'website', 'og:title' => $metadata['title'], 'og:description' => $metadata['description'], 'og:url' => $metadata['canonical'], 'og:image' => $metadata['image'], 'twitter:card' => $metadata['image'] ? 'summary_large_image' : 'summary', 'twitter:title' => $metadata['title'], 'twitter:description' => $metadata['description'], 'twitter:image' => $metadata['image']] as $name => $value) {
                if ($value !== null) $head .= '<meta '.(str_starts_with($name, 'og:') ? 'property' : 'name').'="'.$name.'" content="'.$escape($value).'">';
            }
        }
        $template = preg_replace('#<title>.*?</title>|<meta\s+name="(?:description|robots)"[^>]*>#s', '', $template);
        $template = str_replace('</head>', $head.'</head>', $template);
        // Kept outside the SPA root until its route has loaded; usable without JavaScript.
        return str_replace('<div id="app"></div>', '<main id="site-initial">'.$content.'</main><div id="app"></div>', $template);
    }
}
