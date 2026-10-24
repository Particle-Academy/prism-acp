<?php

declare(strict_types=1);

use Prism\Acp\Credentials\ApiKeyHome;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Ndjson\Json;
use Tests\Support\CredentialPaths;

function expandCredentialValue(mixed $value, string $root): mixed
{
    $nativeHome = (PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME')) ?: '';
    if (is_string($value)) {
        return str_replace(['@root', '@native'], [$root, $nativeHome], $value);
    }
    if (is_array($value)) {
        return array_map(fn (mixed $entry): mixed => expandCredentialValue($entry, $root), $value);
    }
    if ($value instanceof stdClass) {
        $out = new stdClass;
        foreach (get_object_vars($value) as $key => $entry) {
            $out->{$key} = expandCredentialValue($entry, $root);
        }

        return $out;
    }

    return $value;
}

function normalizeCredentialValue(mixed $value, string $root): mixed
{
    if (is_string($value)) {
        return str_replace('\\', '/', str_replace($root, '@root', $value));
    }
    if (is_array($value)) {
        return array_map(fn (mixed $entry): mixed => normalizeCredentialValue($entry, $root), $value);
    }
    if ($value instanceof stdClass) {
        $out = new stdClass;
        foreach (get_object_vars($value) as $key => $entry) {
            $out->{$key} = normalizeCredentialValue($entry, $root);
        }

        return $out;
    }

    return $value;
}

$credentialFixtures = Json::decode((string) file_get_contents(dirname(__DIR__).'/fixtures/credentials.json'));
dataset('credential home oracle', array_combine(array_column($credentialFixtures->scenarios, 'name'), array_map(fn (stdClass $scenario): array => [$scenario], $credentialFixtures->scenarios)));

it('matches the published credential and native home rules', function (stdClass $scenario): void {
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-key-golden-'.bin2hex(random_bytes(5));
    mkdir($root, 0700);
    file_put_contents($root.DIRECTORY_SEPARATOR.'file', 'sentinel');
    try {
        if (($scenario->setup ?? null) === 'aliases') {
            mkdir($root.DIRECTORY_SEPARATOR.'moved');
            CredentialPaths::link($root.DIRECTORY_SEPARATOR.'moved', $root.DIRECTORY_SEPARATOR.'alias');
            CredentialPaths::link($root.DIRECTORY_SEPARATOR.'alias', $root.DIRECTORY_SEPARATOR.'chain');
        }
        $input = expandCredentialValue($scenario, $root);
        $credentials = isset($input->credentials) ? new ProviderCredentials($input->credentials->mode, $input->credentials->apiKey ?? null, $input->credentials->home ?? null) : null;
        try {
            $checked = ApiKeyHome::check($input->provider, $credentials, (array) ($input->parentEnv ?? new stdClass));
            $actual = ['ok' => true, 'result' => $checked === null ? null : ['apiKey' => $checked->key(), 'home' => $checked->home]];
            if ($checked !== null) {
                $actual['env'] = $checked->environment($input->provider);
            }
            $actual = normalizeCredentialValue($actual, $root);
        } catch (Throwable $error) {
            $actual = ['ok' => false, 'message' => $error->getMessage()];
        }
        expect(Json::encode($actual))->toBe(Json::encode($scenario->expected));
    } finally {
        // Only the exact generated fixture children can have been created; never recurse through provider paths.
        CredentialPaths::unlink($root.DIRECTORY_SEPARATOR.'chain');
        CredentialPaths::unlink($root.DIRECTORY_SEPARATOR.'alias');
        if (is_dir($root.DIRECTORY_SEPARATOR.'moved')) {
            rmdir($root.DIRECTORY_SEPARATOR.'moved');
        }
        foreach (['tenant'.DIRECTORY_SEPARATOR.'provider', 'tenant', 'app'] as $child) {
            if (is_dir($root.DIRECTORY_SEPARATOR.$child)) {
                rmdir($root.DIRECTORY_SEPARATOR.$child);
            }
        }
        unlink($root.DIRECTORY_SEPARATOR.'file');
        rmdir($root);
    }
})->with('credential home oracle');
