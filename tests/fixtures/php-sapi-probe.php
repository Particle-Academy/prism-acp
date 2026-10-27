<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Prism\Acp\Claude\PhpRuntime;

$given = getenv('PRISM_TEST_PHP_BINARY');
$row = ['sapi' => PHP_SAPI, 'binary' => PHP_BINARY, 'version' => PHP_VERSION, 'explicit' => $given !== false];
try {
    $row['selected'] = PhpRuntime::resolve($given === false ? null : $given);
    $row['refused'] = false;
} catch (Throwable $error) {
    $row['refused'] = true;
    $row['reason'] = $error->getMessage();
}
echo json_encode($row, JSON_UNESCAPED_SLASHES)."\n";
