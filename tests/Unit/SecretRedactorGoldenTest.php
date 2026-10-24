<?php

declare(strict_types=1);

use Prism\Acp\Credentials\SecretRedactor;
use Prism\Acp\Ndjson\Json;

$redactorFixtures = Json::decode((string) file_get_contents(dirname(__DIR__).'/fixtures/redact.json'));
dataset('published redactor oracle', array_combine(array_column($redactorFixtures->scenarios, 'name'),
    array_map(fn (stdClass $scenario): array => [$scenario], $redactorFixtures->scenarios)));

it('matches every published three-way redaction split and each emitted chunk', function (stdClass $scenario): void {
    try {
        $characters = mb_str_split($scenario->text, 1, 'UTF-8');
        $hash = hash_init('sha256');
        $count = 0;
        for ($first = 0; $first <= count($characters); $first++) {
            for ($second = $first; $second <= count($characters); $second++) {
                $redactor = new SecretRedactor($scenario->secret);
                $output = [$redactor->push(implode('', array_slice($characters, 0, $first))),
                    $redactor->push(implode('', array_slice($characters, $first, $second - $first))),
                    $redactor->push(implode('', array_slice($characters, $second))), $redactor->end(), $redactor->end()];
                hash_update($hash, Json::encode(['first' => $first, 'second' => $second, 'output' => $output])."\n");
                $count++;
            }
        }
        $actual = ['ok' => true, 'splitCount' => $count, 'sha256' => hash_final($hash)];
    } catch (Throwable $error) {
        $actual = ['ok' => false, 'message' => $error->getMessage()];
    }
    expect(Json::encode($actual))->toBe(Json::encode($scenario->expected));
})->with('published redactor oracle');
