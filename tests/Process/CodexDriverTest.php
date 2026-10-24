<?php

declare(strict_types=1);

use Prism\Acp\Agent\DriverEvents;
use Prism\Acp\Agent\PermissionOutcome;
use Prism\Acp\Agent\TurnOutcome;
use Prism\Acp\Async\Deferred;
use Prism\Acp\Async\Loop;
use Prism\Acp\Codex\CodexDriver;
use Prism\Acp\Codex\CodexDriverOptions;
use Prism\Acp\Codex\CodexTransportHandlers;
use Prism\Acp\Codex\CodexTransportOptions;
use Prism\Acp\Codex\StdioCodexTransport;
use Prism\Acp\Credentials\ProviderCredentials;
use Prism\Acp\Process\StopVerdict;

/** A synthetic peer over real pipes and Job Object containment, independent of the injected oracle transport. */
final class CodexHarness
{
    public CodexDriver $driver;

    public string $log;

    /** @var list<array<string,mixed>> */
    public array $updates = [];

    /** @var list<TurnOutcome> */
    public array $turns = [];

    /** @var list<string> */
    public array $problems = [];

    /** @var list<array<string,mixed>> */
    public array $permissions = [];

    public bool $exited = false;

    /** @param array<string,mixed> $options */
    public function __construct(public readonly Loop $loop, array $options = [], ?Closure $decide = null)
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-codex-process-'.bin2hex(random_bytes(5));
        mkdir($directory);
        $this->log = $directory.DIRECTORY_SEPARATOR.'cli.log';
        $parent = [...getenv(), 'FAKE_CODEX_LOG' => $this->log, 'OPENAI_API_KEY' => 'dummy-must-not-be-inherited'];
        $this->driver = new CodexDriver(new CodexDriverOptions(...[
            'cwd' => $directory, 'binary' => PHP_BINARY, 'binaryArgs' => [dirname(__DIR__).'/fixtures/fake-codex.php'],
            'parentEnv' => $parent, 'allowEnv' => ['FAKE_CODEX_LOG'], ...$options,
        ]), new DriverEvents(onUpdate: function (array $update): void {
            $this->updates[] = $update;
        }, onRequestPermission: $decide === null ? null : function (array $request) use ($decide): Deferred {
            $this->permissions[] = $request;
            $result = new Deferred($this->loop);
            $this->loop->delay(0.02, fn () => $result->resolve($decide($request)));

            return $result;
        }, onTurnEnd: function (TurnOutcome $outcome): void {
            $this->turns[] = $outcome;
        }, onProtocolError: function (string $problem): void {
            $this->problems[] = $problem;
        }, onExit: function (): void {
            $this->exited = true;
        }), loop: $loop);
    }

    public function until(Closure $done): void
    {
        $timer = $this->loop->delay(15, static function (): void {
            throw new RuntimeException('Codex process test timed out');
        });
        try {
            $this->loop->run($done);
            if (! $done()) {
                throw new RuntimeException('Codex process ended before condition');
            }
        } finally {
            $this->loop->cancel($timer);
        }
    }

    public function turn(string|array $text): TurnOutcome
    {
        $before = count($this->turns);
        $this->driver->prompt($text);
        $this->until(fn (): bool => count($this->turns) > $before);

        return $this->turns[$before];
    }

    public function said(): string
    {
        return implode('', array_map(static fn (array $update): string => ($update['sessionUpdate'] ?? null) === 'agent_message_chunk' ? $update['content']['text'] : '', $this->updates));
    }

    /** @return list<array<string,mixed>> */
    public function cli(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function requests(string $method): array
    {
        return array_values(array_filter(array_column($this->cli(), 'stdin'), static fn (array $frame): bool => ($frame['method'] ?? null) === $method));
    }

    public function finish(): StopVerdict
    {
        return $this->driver->stop(1000)->await();
    }
}

describe('CodexDriver against a contained process', function () {
    it('logs in with an explicit key before start or resume and uses only the app home', function (?string $resume): void {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-codex-key-'.bin2hex(random_bytes(5));
        mkdir($root);
        $home = $root.DIRECTORY_SEPARATOR.'app';
        $h = new CodexHarness(new Loop, ['credentials' => new ProviderCredentials('apiKey', 'dummy-explicit-key', $home), 'resumeSessionId' => $resume]);
        try {
            $h->driver->start();
            $h->turn('keyed turn');
            $cli = $h->cli();
            $methods = array_column(array_column($cli, 'stdin'), 'method');
            $threadMethod = $resume === null ? 'thread/start' : 'thread/resume';
            expect($cli[0]['codexHome'])->toBe($home)->and($cli[0]['openaiKeyPresent'])->toBeFalse()
                ->and(array_column($cli, 'keyMatchesDummy'))->toBe([true])
                ->and(array_search('account/login/start', $methods, true))->toBeLessThan(array_search($threadMethod, $methods, true))
                ->and(is_file($home.DIRECTORY_SEPARATOR.'fixture-auth.json'))->toBeTrue()
                ->and(file_get_contents($h->log))->not->toContain('dummy-explicit-key')
                ->and(implode(' ', $h->problems))->not->toContain('dummy-explicit-key');
        } finally {
            $h->finish();
            if (is_file($home.DIRECTORY_SEPARATOR.'fixture-auth.json')) {
                unlink($home.DIRECTORY_SEPARATOR.'fixture-auth.json');
            } if (is_dir($home)) {
                rmdir($home);
            } rmdir($root);
        }
    })->with([[null], ['thread-existing']]);

    it('refuses unconfirmed login over real pipes before any thread and redacts echoed errors', function (string $reply): void {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prism-acp-codex-key-fail-'.bin2hex(random_bytes(5));
        mkdir($root);
        $home = $root.DIRECTORY_SEPARATOR.'app';
        $h = new CodexHarness(new Loop, ['credentials' => new ProviderCredentials('apiKey', 'dummy-explicit-key', $home),
            'binaryArgs' => [dirname(__DIR__).'/fixtures/fake-codex.php', '--fixture-login='.$reply]]);
        try {
            $h->driver->start();
            $h->driver->ready()->await();
            expect($h->requests('thread/start'))->toBe([])->and($h->requests('thread/resume'))->toBe([])
                ->and($h->problems)->not->toBe([])->and(implode(' ', $h->problems))->not->toContain('dummy-explicit-key');
        } finally {
            $h->finish();
            if (is_dir($home)) {
                rmdir($home);
            } rmdir($root);
        }
    })->with(['missing', 'wrong', 'error']);

    it('launches app-server, withholds ambient credentials, echoes Unicode and pages models', function () {
        $h = new CodexHarness(new Loop, ['sandbox' => 'read-only', 'model' => 'fixture-model']);
        try {
            $h->driver->start();
            expect($h->turn('hello π😀')->stopReason)->toBe('end_turn');
            $models = $h->driver->modelState()->await();
            expect($h->cli()[0]['argv'])->toBe(['app-server', '--listen', 'stdio://'])
                ->and($h->cli()[0]['env'])->not->toContain('OPENAI_API_KEY')
                ->and($h->driver->withheldCredentials())->toContain('OPENAI_API_KEY')
                ->and($h->driver->processTree()?->containment)->toBe('job-object')
                ->and($h->said())->toBe('echo: hello π😀')
                ->and(array_column($models['availableModels'], 'modelId'))->toBe(['fixture-model', 'second'])
                ->and($h->requests('thread/start')[0]['params'])->toMatchArray(['approvalPolicy' => 'on-request', 'sandbox' => 'read-only', 'model' => 'fixture-model']);
            $h->driver->setModel('second')->await();
            $h->turn('next');
            expect($h->requests('turn/start')[1]['params']['model'])->toBe('second')
                ->and($h->problems)->toBe([]);
        } finally {
            $verdict = $h->finish();
        }
        expect($verdict->verified)->toBeTrue()->and($verdict->empty)->toBeTrue()->and($verdict->survivors)->toBe(0);
    });

    it('resumes the native thread and replays history before a queued prompt', function () {
        $h = new CodexHarness(new Loop, ['resumeSessionId' => 'thread-existing']);
        try {
            $h->driver->start();
            $h->turn('new turn');
            expect($h->driver->cliSessionId())->toBe('thread-existing')
                ->and($h->requests('thread/start'))->toBe([])
                ->and($h->requests('thread/resume')[0]['params'])->toMatchArray(['threadId' => 'thread-existing', 'excludeTurns' => true])
                ->and($h->said())->toBe('replayed historyecho: new turn');
        } finally {
            $h->finish();
        }
    });

    it('cancels one turn and keeps the same contained session for the next turn', function () {
        $h = new CodexHarness(new Loop);
        try {
            $h->driver->start();
            $h->driver->prompt('slow');
            $h->until(fn (): bool => $h->said() === 'working');
            $pid = $h->driver->processTree()?->providerPid;
            $h->driver->cancel()->await();
            $h->until(fn (): bool => count($h->turns) === 1);
            expect($h->turns[0]->stopReason)->toBe('cancelled')->and($h->turn('after cancel')->stopReason)->toBe('end_turn')
                ->and($h->driver->processTree()?->providerPid)->toBe($pid)
                ->and($h->exited)->toBeFalse()->and(count($h->requests('thread/start')))->toBe(1)
                ->and($h->requests('turn/interrupt')[0]['params'])->toMatchArray(['threadId' => 'thread-process-fixture', 'turnId' => 'turn-process-1']);
        } finally {
            $h->finish();
        }
    });

    it('round-trips native approval and denies an absent or unoffered choice', function (?string $choice, string $native) {
        $h = new CodexHarness(new Loop, decide: $choice === null ? null : static fn (): PermissionOutcome => PermissionOutcome::selected($choice));
        try {
            $h->driver->start();
            $h->turn('approval');
            expect($h->said())->toBe('decision: '.$native);
        } finally {
            $h->finish();
        }
    })->with([[null, 'cancel'], ['codex-accept', 'accept'], ['unoffered', 'cancel']]);

    it('creates private typed input files and removes them after the owned process stops', function () {
        $h = new CodexHarness(new Loop);
        $image = null;
        try {
            $h->driver->start();
            $h->turn([(object) ['type' => 'image', 'mimeType' => 'image/png', 'data' => base64_encode('fixture png bytes')],
                (object) ['type' => 'resource', 'resource' => (object) ['uri' => 'file:///fixture.txt', 'text' => 'resource text']]]);
            foreach ($h->cli() as $entry) {
                if (isset($entry['image'])) {
                    $image = $entry['image']['path'];
                    expect($entry['image']['bytes'])->toBe(base64_encode('fixture png bytes'));
                }
            }
            expect($image)->toBeString()->and(is_file($image))->toBeTrue()
                ->and($h->requests('turn/start')[0]['params']['input'][1]['text'])->toContain('resource text');
        } finally {
            $h->finish();
        }
        expect(is_file($image))->toBeFalse();
    });

    it('reports provider exit to the ACP owner during an in-flight turn', function () {
        $h = new CodexHarness(new Loop);
        try {
            $h->driver->start();
            $h->driver->prompt('exit');
            $h->until(fn (): bool => $h->exited);
            expect($h->exited)->toBeTrue();
        } finally {
            $h->finish();
        }
    });

    it('frames real split UTF-8 pipes and withholds malformed input and stderr content', function () {
        $loop = new Loop;
        $frames = $problems = $stderr = [];
        $exited = false;
        $transport = new StdioCodexTransport(new CodexTransportOptions(sys_get_temp_dir(),
            [...getenv(), 'FAKE_CODEX_MODE' => 'framing'], PHP_BINARY, [dirname(__DIR__).'/fixtures/fake-codex.php']),
            new CodexTransportHandlers(onFrame: function (mixed $frame) use (&$frames): void {
                $frames[] = $frame;
            },
                onStderr: function (string $line) use (&$stderr): void {
                    $stderr[] = $line;
                },
                onProblem: function (string $line) use (&$problems): void {
                    $problems[] = $line;
                },
                onExit: function () use (&$exited): void {
                    $exited = true;
                }), $loop);
        $timer = $loop->delay(15, static function (): void {
            throw new RuntimeException('transport fixture timed out');
        });
        try {
            $transport->start()->await();
            $loop->run(static function () use (&$exited): bool {
                return $exited;
            });
            expect($frames[0]->params->text)->toBe('π😀')->and($problems)->toHaveCount(1)
                ->and(implode(' ', $problems))->not->toContain('private', 'bearer')
                ->and($stderr)->toBe(['Codex stderr line withheld (27 characters)']);
        } finally {
            $loop->cancel($timer);
            $transport->stop(1000)->await();
        }
    });
})->skip(PHP_OS_FAMILY !== 'Windows', 'The PHP provider transport currently supports Windows Job Objects only');
