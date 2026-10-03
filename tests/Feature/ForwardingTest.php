<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\Forwarder;
use Mbolli\PhpVia\State\SharedContextDirectory;
use Mbolli\PhpVia\Testing\TestRequest;

/*
 * With more than one worker, a tab lives on the worker that holds its SSE stream. An action or a download that
 * reaches another worker went to a copy of the context rebuilt there, which no stream read: the request answered 200
 * and its TAB signals, sync(), scripts and downloads never reached the browser. Such requests now go to the tab's
 * worker over the worker pipe, and its answer comes back. The fixtures run real servers over HTTP/1.1 and h2c.
 */

/** @return array<string, string> the key=value lines of a fixture run, and its output under 'out' */
function forwardingFixture(string $fixture, string ...$args): array {
    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/' . $fixture)
        . ' ' . implode(' ', array_map(escapeshellarg(...), $args)) . ' 2>&1');
    preg_match_all('/^([a-z_0-9]+)=(\S*)$/m', $out, $m);

    return array_combine($m[1], $m[2]) + ['out' => $out];
}

describe('over HTTP/1.1', function (): void {
    test('a tab\'s actions, cookies, uploads and downloads reach it from any worker', function (int $workers): void {
        $r = forwardingFixture('forwarding_workers.php', (string) $workers, 'layouts');

        expect($r)->toMatchArray([
            'stream_open' => '1',
            'stream_worker_other' => '1',
            'rebuilt' => '2:hello',
            'bump1' => '200',
            'bump2' => '200',
            'stream_n' => '2',
            'stream_script' => '1',
            'stream_spawned' => '1',
            'stream_s' => '1',
            'stream_r' => '1',
            'cookie' => '200:1',
            'held_page_worker' => '0',
            'held_stream_worker' => '1',
            'upload' => '200:1',
            'download' => '200:1',
            'login' => '200:1',
            'bump_new_cookie' => '200',
            'stream_n_after_login' => '3',
            'curl_bump' => '200',
            'curl_stream_n' => '1',
            'curl_stream_script' => '1',
        ], $r['out']);
    })->with([2, 4]);

    test('an action that outlasts the forward timeout answers 504, finishes, and its cookie goes out with the next response', function (): void {
        $r = forwardingFixture('forwarding_workers.php', '2', 'timeout');

        expect($r)->toMatchArray([
            'slow' => '504:1:0',
            'slow_finished' => '100',
            'next' => '200:1',
        ], $r['out']);
    });

    test('a stream that moves to another worker takes the TAB signals and the output of actions the old one ran', function (): void {
        $r = forwardingFixture('forwarding_workers.php', '2', 'handover');

        expect($r)->toMatchArray([
            'early' => '200:42:7:1',
            'moved' => '200:100:1:0',
            'owned' => '7',
        ], $r['out']);
    });

    test('with revival off a tab\'s actions from another worker still reach it', function (): void {
        $r = forwardingFixture('forwarding_workers.php', '2', 'norevival');

        expect($r)->toMatchArray([
            'norevival_early' => '200',
            'norevival_bump' => '200',
            'norevival_stream_n' => '2',
            'norevival_stream_script' => '1',
            'norevival_held_receiver' => '0',
        ], $r['out']);
    });

    test('when the worker holding the tab dies mid-action the receiver answers 503 without running it, and takes the tab', function (): void {
        $r = forwardingFixture('forwarding_workers.php', '2', 'crash');

        expect($r)->toMatchArray([
            'die' => '503:1',
            'after' => '200',
            'held_receiver' => '1',
            'died_once' => '1',
            'receiver_alive' => '1',
        ], $r['out']);
    });
});

describe('over h2c', function (): void {
    test('actions on a connection another worker holds reach the tab\'s stream', function (int $workers): void {
        if (trim((string) shell_exec('command -v curl')) === '') {
            $this->markTestSkipped('curl required for the h2c stream');
        }
        $r = forwardingFixture('forwarding_h2c.php', (string) $workers);

        expect($r)->toMatchArray([
            'page' => '200:1',
            'stream_open' => '1',
            'bump' => '200',
            'stream_n' => '2',
            'stream_script' => '1',
            'stream_s' => '1',
            'stream_r' => '1',
            'cookie' => '200:1',
            'stream_worker_kept' => '1',
        ], $r['out']);
    })->with([2, 4]);
});

describe('the home record', function (): void {
    beforeEach(function (): void {
        $this->directory = new SharedContextDirectory(maxRows: 64);
        $this->directory->put('/p_/a', ['route' => '/p', 'params' => [], 'sessionId' => null, 'expiresAt' => time() + 60], [0, 100]);
        $this->live = static fn (array $home): bool => $home[1] !== 999;
    });

    test('a page load writes its worker as the home, and a heartbeat put keeps it', function (): void {
        $this->directory->put('/p_/a', ['route' => '/p', 'params' => [], 'sessionId' => null, 'expiresAt' => time() + 60]);

        expect($this->directory->home('/p_/a'))->toBe([0, 100]);
    });

    test('a stream takes the tab from a live home', function (): void {
        expect($this->directory->claimHome('/p_/a', [1, 200], true, null, $this->live))->toBe([true, [0, 100]])
            ->and($this->directory->home('/p_/a'))->toBe([1, 200])
        ;
    });

    test('a request leaves a live home it did not expect, and replaces the one that refused it', function (): void {
        expect($this->directory->claimHome('/p_/a', [1, 200], false, null, $this->live))->toBe([false, [0, 100]])
            ->and($this->directory->claimHome('/p_/a', [1, 200], false, [0, 100], $this->live))->toBe([true, [0, 100]])
            ->and($this->directory->home('/p_/a'))->toBe([1, 200])
        ;
    });

    test('a request replaces a home whose process is gone', function (): void {
        $this->directory->claimHome('/p_/a', [0, 999], true, null, $this->live);

        expect($this->directory->claimHome('/p_/a', [1, 200], false, null, $this->live))->toBe([true, [0, 999]]);
    });

    test('with revival off a row holds the home only, and goes when that home releases it', function (): void {
        $this->directory->putHome('/p_/b', time() + 60, [0, 100]);
        $stateWritten = $this->directory->changeState('/p_/b', static fn (array $state): array => ['' => ['k' => 's:1:"v";']], 'k');
        $this->directory->putHome('/p_/b', time() + 60);

        expect($this->directory->home('/p_/b'))->toBe([0, 100])
            ->and($this->directory->get('/p_/b'))->toBeNull()
            ->and($this->directory->getState('/p_/b'))->toBeNull()
            ->and($stateWritten)->toBeFalse()
        ;

        $this->directory->releaseHome('/p_/b', [1, 200]);
        expect($this->directory->home('/p_/b'))->toBe([0, 100]);

        $this->directory->releaseHome('/p_/b', [0, 100]);
        $this->directory->releaseHome('/p_/a', [0, 100]);
        expect($this->directory->home('/p_/b'))->toBeNull()
            ->and($this->directory->home('/p_/a'))->toBe([0, 100])
        ;
    });

    test('a context without a row has no home to claim', function (): void {
        expect($this->directory->claimHome('/p_/none', [1, 200], true, null, $this->live))->toBe([false, null])
            ->and($this->directory->home('/p_/none'))->toBeNull()
        ;
    });
});

describe('the forwarded request', function (): void {
    test('is the request as HTTP/1.1 bytes, with a body length of its own and the cookies HTTP/2 hands over parsed', function (): void {
        $request = new TestRequest('POST', '/_action/bump', ['from' => 'button'], ['via_session_id' => 'a b'], [
            'content-type' => 'application/json',
            'content-length' => '999',
            'transfer-encoding' => 'chunked',
            'origin' => 'http://localhost',
        ], '{"via_ctx":"/p_/a"}');

        expect(Forwarder::rawRequest($request))->toBe(
            "POST /_action/bump?from=button HTTP/1.1\r\nhost: localhost\r\ncontent-type: application/json\r\norigin: http://localhost\r\n"
            . "cookie: via_session_id=a%20b\r\ncontent-length: 19\r\n\r\n{\"via_ctx\":\"/p_/a\"}"
        );
    });
});
