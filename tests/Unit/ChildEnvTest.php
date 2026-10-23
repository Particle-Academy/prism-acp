<?php

declare(strict_types=1);

use Prism\Acp\Env\ChildEnv;

/*
 * Ported from prism-acp-ts test/env.test.ts. An inherited API key OUTRANKS a CLI's subscription login, so it must never
 * reach the child unless the caller says so by the one long, explicit option.
 */
const SECRET = 'sk-ant-api03-THIS-MUST-NOT-REACH-THE-CHILD';

describe('the credential strip', function () {
    it('withholds an inherited ANTHROPIC_API_KEY', function () {
        $r = ChildEnv::build(['PATH' => '/usr/bin', 'ANTHROPIC_API_KEY' => SECRET]);
        expect($r->env)->not->toHaveKey('ANTHROPIC_API_KEY')->and($r->withheld)->toBe(['ANTHROPIC_API_KEY']);
    });

    it('reports the NAME it withheld and never the value', function () {
        $r = ChildEnv::build(['ANTHROPIC_API_KEY' => SECRET]);
        expect(json_encode([$r->env, $r->withheld]))->not->toContain('sk-ant');
    });

    it('withholds a LOWERCASE spelling, because Windows env names are case-insensitive', function () {
        $r = ChildEnv::build(['anthropic_api_key' => SECRET]);
        expect(array_values($r->env))->not->toContain(SECRET)->and($r->withheld)->toBe(['anthropic_api_key']);
    });

    it('withholds a MixedCase spelling', fn () => expect(array_values(ChildEnv::build(['Anthropic_Api_Key' => SECRET])->env))->not->toContain(SECRET));

    it('withholds every name in the outranking list', function () {
        $r = ChildEnv::build(array_fill_keys(ChildEnv::OUTRANKING_CREDENTIALS, SECRET));
        $withheld = $r->withheld;
        $expected = ChildEnv::OUTRANKING_CREDENTIALS;
        sort($withheld);
        sort($expected);
        expect($r->env)->toBe([])->and($withheld)->toBe($expected);
    });

    it('refuses an outranking credential EVEN when the caller allow-lists it', function () {
        $r = ChildEnv::build(['ANTHROPIC_API_KEY' => SECRET], allow: ['ANTHROPIC_API_KEY']);
        expect($r->env)->not->toHaveKey('ANTHROPIC_API_KEY')->and($r->withheld)->toBe(['ANTHROPIC_API_KEY']);
    });

    it('passes one through only under the explicitly named option (the positive control)', function () {
        $r = ChildEnv::build(['ANTHROPIC_API_KEY' => SECRET], allowSubscriptionOverridingCredentials: true);
        expect($r->env['ANTHROPIC_API_KEY'])->toBe(SECRET)->and($r->withheld)->toBe([]);
    });
});

describe('the allow-list', function () {
    it('passes through what a CLI needs to run', function () {
        $parent = ['PATH' => '/usr/bin', 'HOME' => '/home/u', 'SYSTEMROOT' => 'C:\\Windows'];
        expect(ChildEnv::build($parent)->env)->toBe($parent);
    });

    it('drops an unlisted variable rather than passing it on', function () {
        $env = ChildEnv::build(['PATH' => '/usr/bin', 'MY_COMPANY_INTERNAL_TOKEN' => 'x'])->env;
        expect($env)->not->toHaveKey('MY_COMPANY_INTERNAL_TOKEN')->and($env['PATH'])->toBe('/usr/bin');
    });

    it('passes an extra the caller asked for', fn () => expect(ChildEnv::build(['CLAUDE_CONFIG_DIR' => '/c'], allow: ['CLAUDE_CONFIG_DIR'])->env['CLAUDE_CONFIG_DIR'])->toBe('/c'));

    it('matches allow-list names case-insensitively too (Path on Windows)', fn () => expect(ChildEnv::build(['Path' => 'C:\\bin'])->env['Path'])->toBe('C:\\bin'));

    it('skips a variable whose value is null', function () {
        $env = ChildEnv::build(['PATH' => null, 'HOME' => '/home/u'])->env;
        expect($env)->not->toHaveKey('PATH')->and($env['HOME'])->toBe('/home/u');
    });

    it('lets $set win over the parent', fn () => expect(ChildEnv::build(['PATH' => '/usr/bin'], set: ['PATH' => '/opt/bin'])->env['PATH'])->toBe('/opt/bin'));
});

describe('it never touches the ambient environment', function () {
    it('leaves the process environment alone', function () {
        putenv('PRISM_ACP_AMBIENT_PROBE=present');
        try {
            ChildEnv::build([...getenv(), 'ANTHROPIC_API_KEY' => SECRET]);
            expect(getenv('PRISM_ACP_AMBIENT_PROBE'))->toBe('present');
        } finally {
            putenv('PRISM_ACP_AMBIENT_PROBE');
        }
    });
});

describe('the lists themselves', function () {
    it('has no name in both lists', function () {
        $allow = array_map('strtoupper', ChildEnv::BASE_ALLOW);
        expect(array_values(array_filter(ChildEnv::OUTRANKING_CREDENTIALS, fn ($n) => in_array(strtoupper($n), $allow, true))))->toBe([]);
    });

    it('is the reference list, name for name (prism-acp-ts src/env.ts)', function () {
        expect(count(ChildEnv::OUTRANKING_CREDENTIALS))->toBe(8)->and(count(ChildEnv::BASE_ALLOW))->toBe(33);
    });
});
