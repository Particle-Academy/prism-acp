<?php

declare(strict_types=1);

use Prism\Acp\Codex\CodexRateLimit;
use Prism\Acp\Ndjson\Json;

it('accepts current decimal-string credits and rejects coercion tricks', function () {
    foreach ([['0', 0], ['12.5', 12.5], ['001.25', 1.25], [0, 0], [null, null]] as [$balance, $expected]) {
        $read = CodexRateLimit::read(Json::decode(Json::encode(['primary' => null, 'secondary' => null, 'credits' => [
            'hasCredits' => true, 'unlimited' => false, 'balance' => $balance,
        ]])));
        expect($read)->toBeInstanceOf(CodexRateLimit::class);
        expect($read->credits['balance'])->toEqual($expected);
    }
    foreach ([' 0', '0 ', '+1', '-1', '1e2', '.5', '1.', "0\n", '0x10', 'NaN', str_repeat('9', 400), true, []] as $bad) {
        $read = CodexRateLimit::read(Json::decode(Json::encode(['primary' => null, 'secondary' => null, 'credits' => [
            'hasCredits' => true, 'unlimited' => false, 'balance' => $bad,
        ]])));
        expect($read)->toBeString()->toContain('credits.balance expected finite non-negative decimal');
    }
});
