<?php

declare(strict_types=1);

require $argv[1];

use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Support\DirectoryProtectionException;
use Prism\Acp\Support\PrivateDirectory;

$root = $argv[2];
if (($argv[3] ?? '') === 'restricted') {
    $fresh = $root.DIRECTORY_SEPARATOR.'user-home';
    if (file_exists($fresh) || ! mkdir($fresh, 0700)) {
        throw new RuntimeException('filtered-token home must be created fresh by that token');
    }
    PrivateDirectory::protect($fresh);
}
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
