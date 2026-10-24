<?php

declare(strict_types=1);

it('keeps account emails and workstation home paths out of provider captures', function () {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/fixtures'));
    $checked = 0;
    foreach ($files as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['json', 'ndjson', 'txt'], true)) {
            continue;
        }
        $text = (string) file_get_contents($file->getPathname());
        expect($text, $file->getFilename())->not->toMatch('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i')
            ->not->toMatch('~(?:[A-Z]:[\\\\/]+Users[\\\\/]+|/home/|/Users/)[A-Z0-9_-]+~i');
        $checked++;
    }
    expect($checked)->toBeGreaterThan(80);
});
