<?php

declare(strict_types=1);

require dirname(__DIR__).'/tests/Architecture/ArchitectureRules.php';

use App\Tests\Architecture\ArchitectureRules;

$root = dirname(__DIR__);
$adapters = require $root.'/tests/Architecture/adapters.php';
$errors = [];
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    ++$count;
    $path = substr($file->getPathname(), strlen($root.'/src/'));
    foreach (ArchitectureRules::violations($path, file_get_contents($file->getPathname()), $adapters) as $error) {
        $errors[] = $path.': '.$error;
    }
}
foreach ($adapters as $path => $reason) {
    if (!is_file($root.'/src/'.$path) || trim($reason) === '') {
        $errors[] = 'Stale or undocumented adapter: '.$path;
    }
}
// Detect obsolete FQCNs in PHP, DI, routes and test fixtures, including strings.
// Documentation deliberately retains historical source names.
foreach (['src', 'config', 'tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!in_array($file->getExtension(), ['php', 'yaml', 'yml', 'xml'], true)) {
            continue;
        }
        $source = str_replace('\\\\', '\\', file_get_contents($file->getPathname()));
        preg_match_all('/App\\\\(?:Service|Availability|Booking|Planning|Staff|Resource|Payment|GiftVoucher|Email|Waitlist|Gdpr|Dashboard|Configuration|Tenant|Observability|Security|Reminder)\\\\[A-Za-z0-9_\\\\]+/', $source, $matches);
        foreach ($matches[0] as $name) {
            $target = $root.'/src/'.str_replace('\\', '/', substr($name, 4)).'.php';
            if (!is_file($target) && !is_dir(substr($target, 0, -4))) {
                $errors[] = substr($file->getPathname(), strlen($root) + 1).': obsolete reference '.$name;
            }
        }
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode("\n", array_unique($errors))."\n");
    exit(1);
}
echo "Architecture OK ($count PHP source files).\n";
