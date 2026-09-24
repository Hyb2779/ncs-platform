<?php

$root = dirname(__DIR__).'/lang';
$locales = ['tr', 'en', 'de', 'ar'];
$catalog = [];

foreach ($locales as $locale) {
    $catalog[$locale] = [];
    $directory = $root.'/'.$locale;

    if (! is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($directory) + 1, -4);
        $group = str_replace('\\', '/', $relative);
        $messages = include $file->getPathname();

        if (! is_array($messages)) {
            continue;
        }

        $catalog[$locale][$group] = $messages;
    }
}

echo json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
