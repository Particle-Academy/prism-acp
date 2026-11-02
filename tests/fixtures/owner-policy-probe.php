<?php

declare(strict_types=1);

require $argv[1];

use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Support\DirectoryProtectionException;

$root = $argv[2];
$observations = [];
foreach (['user-home', 'admin-home', 'foreign-home'] as $name) {
    try {
        ApiKeyHome::check('codex', new ProviderCredentials('apiKey', 'owned-argument-key', $root.DIRECTORY_SEPARATOR.$name), []);
        $observations[$name] = true;
    } catch (DirectoryProtectionException) {
        $observations[$name] = false;
    }
}
echo json_encode(['ownerPolicy' => $observations, 'credentialContentsAccessed' => false], JSON_THROW_ON_ERROR)."\n";
