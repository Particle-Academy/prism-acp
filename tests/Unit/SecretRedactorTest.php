<?php

declare(strict_types=1);

use Prism\Acp\Credentials\SecretRedactor;
use Prism\Acp\Support\Utf8Stream;

it('redacts complete secrets at every byte boundary without breaking diagnostic UTF-8', function (string $secret): void {
    $text = '前缀 '.$secret.' 中间 '.$secret.' 后缀';
    for ($split = 0; $split <= strlen($text); $split++) {
        $redactor = new SecretRedactor($secret);
        $utf8 = new Utf8Stream;
        $out = $redactor->push($utf8->push(substr($text, 0, $split))).$redactor->push($utf8->push(substr($text, $split)));
        $out .= $redactor->push($utf8->end()).$redactor->end();
        expect($out)->toBe(str_replace($secret, SecretRedactor::REDACTED, $text))->toContain('前缀', '中间', '后缀')
            ->and(mb_check_encoding($out, 'UTF-8'))->toBeTrue();
    }
    $redactor = new SecretRedactor($secret);
    $utf8 = new Utf8Stream;
    $out = '';
    foreach (str_split($text) as $byte) {
        $out .= $redactor->push($utf8->push($byte));
    }
    $out .= $redactor->push($utf8->end()).$redactor->end();
    expect($out)->toBe(str_replace($secret, SecretRedactor::REDACTED, $text))->and(mb_check_encoding($out, 'UTF-8'))->toBeTrue();
})->with(['dummy-explicit-key', 'a', '***', '*#•', '密钥😀']);
