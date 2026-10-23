<?php

declare(strict_types=1);

use Prism\Acp\Meta;

describe('meta keys', function () {
    it('namespaces a bare name', fn () => expect(Meta::key('model'))->toBe('particle.academy/model'));

    it('refuses an empty name, a slash, and ACP\'s reserved trace keys', function (string $name) {
        expect(fn () => Meta::key($name))->toThrow(InvalidArgumentException::class);
    })->with(['', 'a/b', 'traceparent', 'tracestate', 'baggage']);

    it('merges into existing _meta rather than replacing it', function () {
        $update = Meta::with(['sessionUpdate' => 'notice', '_meta' => ['a' => 1]], ['b' => 2]);
        expect($update)->toBe(['sessionUpdate' => 'notice', '_meta' => ['a' => 1, 'b' => 2]]);
    });
});

describe('a model id', function () {
    it('passes an ordinary name through', fn () => expect(Meta::assertModel('claude-fable-5-1'))->toBe('claude-fable-5-1'));

    it('refuses an empty or flag-shaped model', function (string $model) {
        expect(fn () => Meta::assertModel($model))->toThrow(InvalidArgumentException::class, 'refusing model');
    })->with(['', '   ', '-x', '--dangerously-skip-permissions']);
});
