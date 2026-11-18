<?php
declare(strict_types=1);

// Compile every application class so the denominator includes untouched files.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
foreach ($files as $file) {
    if ($file->getExtension() === 'php') {
        require_once $file->getPathname();
    }
}
