<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$excludeDirectories = ['vendor', 'var', 'node_modules', 'public/build', '.git'];
$textExtensions = ['php', 'twig', 'html', 'js', 'css', 'yaml', 'yml', 'env', 'md', 'txt', 'xml', 'json', 'csv'];
$suspiciousPatterns = [
    '/Ã./u',
    '/â€™/u',
    '/â€œ/u',
    '/â€\x9d/u',
    '/â€“/u',
    '/â€”/u',
    '/â€¦/u',
    '/Â./u',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $current) use ($excludeDirectories): bool {
            if ($current->isDir()) {
                return !in_array($current->getFilename(), $excludeDirectories, true);
            }

            return true;
        }
    )
);

$issues = [];

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $relativePath = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
    $extension = strtolower($file->getExtension());

    if ($relativePath === 'bin' . DIRECTORY_SEPARATOR . 'check-utf8.php') {
        continue;
    }

    if (!in_array($extension, $textExtensions, true) && !in_array($file->getFilename(), ['.env', '.env.local', '.env.test'], true)) {
        continue;
    }

    $content = file_get_contents($file->getPathname());
    if ($content === false) {
        $issues[] = [$relativePath, 'Lecture impossible'];
        continue;
    }

    if (!mb_check_encoding($content, 'UTF-8')) {
        $issues[] = [$relativePath, 'Fichier non valide en UTF-8'];
        continue;
    }

    $lines = preg_split("/\R/u", $content) ?: [];
    foreach ($lines as $index => $line) {
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $issues[] = [$relativePath, sprintf('Ligne %d: séquence suspecte: %s', $index + 1, trim($line))];
                break;
            }
        }
    }
}

if ($issues === []) {
    echo "OK: aucun problème d'encodage UTF-8 détecté.\n";
    exit(0);
}

foreach ($issues as [$path, $message]) {
    echo $path . ' => ' . $message . PHP_EOL;
}

exit(1);
