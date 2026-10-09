#!/usr/bin/env php
<?php

/*
 * Integration-style coverage for commit 70e0e41 ("Batch Loki pushes to fix EMFILE crashes").
 *
 * The last commit replaced the one-pooled-request-per-log-line fan-out in
 * loki:persist with a single push per 1000-line chunk grouped by label set,
 * drained the buffer under LOCK_EX, requeued unsent lines on failure, and
 * locked writer appends with seek-to-end. These tests pin that behavior.
 *
 * No PHPUnit or full Laravel app is required: the suite boots a minimal
 * container, fakes the Http facade, and uses real temp files as the buffer.
 *
 * Run: php tests/integration_test.php   (exit 0 = all pass)
 */

require __DIR__.'/../vendor/autoload.php';

use AlexMacArthur\LaravelLokiLogging\L3Logger;
use AlexMacArthur\LaravelLokiLogging\L3Persister;
use AlexMacArthur\LaravelLokiLogging\L3ServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;

// ---------------------------------------------------------------------------
// Bootstrap: minimal container + Http facade + Laravel helper stubs.
// ---------------------------------------------------------------------------

$app = Container::getInstance();
$app->singleton(Factory::class, fn () => new Factory);
Facade::setFacadeApplication($app);

if (! function_exists('config')) {
    function config($key = null, $default = null)
    {
        $cfg = $GLOBALS['__l3_config'] ?? [];
        if ($key === null) {
            return $cfg;
        }
        $value = $cfg;
        foreach (explode('.', $key) as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}

if (! function_exists('storage_path')) {
    function storage_path($path = '')
    {
        return $GLOBALS['__l3_storage'].'/'.$path;
    }
}

/** Persister that captures console errors instead of needing Artisan output. */
class TestablePersister extends L3Persister
{
    /** @var array<int, string> */
    public array $errors = [];

    public function error($string, $verbosity = null)
    {
        $this->errors[] = $string;
    }
}

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------

$pass = 0;
$fail = 0;
$failures = [];

function test(string $name, Closure $fn): void
{
    global $pass, $fail, $failures;
    try {
        $fn();
        $pass++;
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $fail++;
        $failures[] = $name;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}

function assertTrue(bool $cond, string $message = 'expected true'): void
{
    if (! $cond) {
        throw new Exception($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new Exception(($message !== '' ? $message.' ' : '').'expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

function resetHttp(): void
{
    $app = Container::getInstance();
    $app->instance(Factory::class, new Factory);
    Facade::clearResolvedInstances();
}

/** Fresh temp storage dir acting as the Laravel storage path. */
function freshStorage(array $loki = ['server' => 'http://loki:3100', 'username' => 'u', 'password' => 'p']): string
{
    $base = sys_get_temp_dir().'/l3-int-'.uniqid();
    mkdir($base.'/logs', 0777, true);
    $GLOBALS['__l3_storage'] = $base;
    $GLOBALS['__l3_config'] = ['l3' => ['loki' => $loki]];

    return $base;
}

function bufferFile(): string
{
    return storage_path(L3ServiceProvider::LOG_LOCATION);
}

function makeLine(array $tags, string $message, int $timeMicros = 1700000000000000): string
{
    return json_encode(['time' => $timeMicros, 'tags' => $tags, 'message' => $message])."\n";
}

function persist(): TestablePersister
{
    $persister = new TestablePersister;
    $persister->handle();

    return $persister;
}

/** Build an L3Logger without a Laravel app by injecting its dependencies. */
function newLogger(mixed $file, array $context = [], string $format = '[{level_name}] {message}'): L3Logger
{
    $logger = (new ReflectionClass(L3Logger::class))->newInstanceWithoutConstructor();
    $set = function (string $prop, mixed $value) use ($logger): void {
        $property = new ReflectionProperty(L3Logger::class, $prop);
        $property->setValue($logger, $value);
    };
    $set('file', $file);
    $set('context', $context);
    $set('format', $format);

    return $logger;
}

function newRecord(string $message = 'hello', array $context = [], Level $level = Level::Info): LogRecord
{
    return new LogRecord(new DateTimeImmutable('2024-01-01T00:00:00+00:00'), 'chan', $level, $message, $context, []);
}

function chunkSize(): int
{
    return (int) (new ReflectionClass(L3Persister::class))->getConstant('CHUNK_SIZE');
}

function maxBufferBytes(): int
{
    return (int) (new ReflectionClass(L3Persister::class))->getConstant('MAX_BUFFER_BYTES');
}

// ---------------------------------------------------------------------------
// groupIntoStreams(): the pure batching core of the EMFILE fix.
// ---------------------------------------------------------------------------

test('groupIntoStreams groups identical label sets into one stream', function (): void {
    $lines = [makeLine(['app' => 'x'], 'one'), makeLine(['app' => 'x'], 'two')];

    $streams = L3Persister::groupIntoStreams($lines);

    assertSame(1, count($streams));
    assertSame(['app' => 'x'], $streams[0]['stream']);
    assertSame(2, count($streams[0]['values']));
    assertSame('one', $streams[0]['values'][0][1]);
    assertSame('two', $streams[0]['values'][1][1]);
});

test('groupIntoStreams splits distinct label sets and is key-order independent', function (): void {
    $lines = [
        makeLine(['app' => 'x', 'env' => 'prod'], 'a'),
        makeLine(['app' => 'y', 'env' => 'prod'], 'b'),
        makeLine(['env' => 'prod', 'app' => 'x'], 'c'), // same labels, different key order
    ];

    $streams = L3Persister::groupIntoStreams($lines);

    assertSame(2, count($streams));
    assertSame(2, count($streams[0]['values']));
    assertSame(1, count($streams[1]['values']));
});

test('groupIntoStreams converts microsecond timestamps to nanosecond strings', function (): void {
    $streams = L3Persister::groupIntoStreams([makeLine(['app' => 'x'], 'hi', 1700000000000000)]);

    // Preserves the pre-batch behavior of strval($time * 1000).
    assertSame('1700000000000000000', $streams[0]['values'][0][0]);
});

test('groupIntoStreams skips malformed and blank lines', function (): void {
    $lines = ["not json\n", "\n", "   \n", makeLine(['app' => 'x'], 'ok')];

    $streams = L3Persister::groupIntoStreams($lines);

    assertSame(1, count($streams));
    assertSame('ok', $streams[0]['values'][0][1]);
});

test('groupIntoStreams filters non-scalar tags and sorts labels deterministically', function (): void {
    $lines = [makeLine(['z' => 'last', 'n' => 7, 'b' => true, 'nil' => null, 'arr' => ['x'], 'obj' => (object) []], 'm')];

    $streams = L3Persister::groupIntoStreams($lines);

    assertSame(['b' => '1', 'n' => '7', 'nil' => '', 'z' => 'last'], $streams[0]['stream']);
});

test('groupIntoStreams defaults missing time, tags, and message', function (): void {
    $streams = L3Persister::groupIntoStreams([json_encode(['tags' => ['a' => 'b']])."\n"]);

    assertSame(1, count($streams));
    assertSame(['a' => 'b'], $streams[0]['stream']);
    assertTrue(is_numeric($streams[0]['values'][0][0]), 'fallback timestamp should be numeric');

    $raw = json_encode(['time' => 1700000000000000]);
    $streams = L3Persister::groupIntoStreams([$raw."\n"]);
    assertSame([], $streams[0]['stream']);
    assertSame($raw, $streams[0]['values'][0][1]);
});

test('groupIntoStreams returns empty array for empty input', function (): void {
    assertSame([], L3Persister::groupIntoStreams([]));
});

// ---------------------------------------------------------------------------
// handle(): one batched push per chunk (the EMFILE regression test).
// ---------------------------------------------------------------------------

test('handle pushes one request per chunk and clears the buffer', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);
    file_put_contents(bufferFile(), makeLine(['app' => 'x'], 'one').makeLine(['app' => 'x'], 'two'));

    $persister = persist();

    assertSame([], $persister->errors);
    assertSame(1, count(Http::recorded()), '5 lines must produce 1 request, not 1 per line');
    [$request] = Http::recorded()[0];
    assertSame('http://loki:3100/loki/api/v1/push', $request->url());
    $data = $request->data();
    assertSame(1, count($data['streams']));
    assertSame(2, count($data['streams'][0]['values']));
    assertSame('', file_get_contents(bufferFile()), 'buffer must be drained on success');
});

test('handle sends basic-auth credentials with the push', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);
    file_put_contents(bufferFile(), makeLine(['app' => 'x'], 'hi'));

    persist();

    [$request] = Http::recorded()[0];
    assertTrue(str_starts_with((string) $request->header('Authorization')[0], 'Basic '), 'expected a Basic Authorization header');
});

test('handle trims a trailing slash from the configured server', function (): void {
    freshStorage(['server' => 'http://loki:3100/', 'username' => 'u', 'password' => 'p']);
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);
    file_put_contents(bufferFile(), makeLine(['app' => 'x'], 'hi'));

    persist();

    [$request] = Http::recorded()[0];
    assertSame('http://loki:3100/loki/api/v1/push', $request->url());
});

test('handle sends distinct label sets as multiple streams in one request', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);
    file_put_contents(
        bufferFile(),
        makeLine(['app' => 'x'], 'a').makeLine(['app' => 'y'], 'b').makeLine(['app' => 'x'], 'c')
    );

    persist();

    assertSame(1, count(Http::recorded()));
    [$request] = Http::recorded()[0];
    assertSame(2, count($request->data()['streams']));
});

test('handle chunks large buffers instead of fanning out per line', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);

    $size = chunkSize();
    $total = $size * 2 + 5;
    $lines = '';
    for ($i = 0; $i < $total; $i++) {
        $lines .= makeLine(['app' => 'x'], "msg-{$i}");
    }
    file_put_contents(bufferFile(), $lines);

    $persister = persist();

    assertSame([], $persister->errors);
    assertSame(3, count(Http::recorded()), "expected 3 chunked requests for {$total} lines");
    $pushed = 0;
    foreach (Http::recorded() as [$request]) {
        $values = 0;
        foreach ($request->data()['streams'] as $stream) {
            $values += count($stream['values']);
        }
        assertTrue($values <= $size, "chunk of {$values} exceeds CHUNK_SIZE {$size}");
        $pushed += $values;
    }
    assertSame($total, $pushed, 'every buffered line must be pushed exactly once');
    assertSame('', file_get_contents(bufferFile()));
});

test('handle requeues the buffer when Loki returns an error', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('boom', 500)]);
    $before = makeLine(['app' => 'x'], 'm1').makeLine(['app' => 'x'], 'm2');
    file_put_contents(bufferFile(), $before);

    $persister = persist();

    assertSame($before, file_get_contents(bufferFile()), 'failed push must not drop buffered lines');
    assertTrue(count($persister->errors) === 1, 'failure must be reported via console error');
    assertTrue(count(Http::recorded()) >= 1, 'push must have been attempted');
});

test('handle requeues the buffer when the push throws', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(function (): void {
        throw new Exception('conn refused');
    });
    $before = makeLine(['app' => 'x'], 'm3');
    file_put_contents(bufferFile(), $before);

    $persister = persist();

    assertSame($before, file_get_contents(bufferFile()));
    assertSame(1, count($persister->errors));
    assertTrue(str_contains($persister->errors[0], 'conn refused'));
});

test('handle reports and skips when no server is configured', function (): void {
    freshStorage(['server' => null, 'username' => 'u', 'password' => 'p']);
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);
    file_put_contents(bufferFile(), makeLine(['app' => 'x'], 'hi'));

    $persister = persist();

    assertSame(0, count(Http::recorded()), 'no request without a server');
    assertSame(1, count($persister->errors));
    assertTrue(str_contains($persister->errors[0], 'LOG_SERVER'));
    assertTrue(file_exists(bufferFile()), 'untouched buffer must remain for a later run');
});

test('handle is a no-op when the buffer file is missing', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);

    $persister = persist();

    assertSame(0, count(Http::recorded()));
    assertSame([], $persister->errors);
});

test('handle sends nothing for an empty buffer', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);
    file_put_contents(bufferFile(), "\n   \n");

    $persister = persist();

    assertSame(0, count(Http::recorded()));
});

test('handle drains a malformed-only buffer without pushing', function (): void {
    freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);
    file_put_contents(bufferFile(), "garbage\nmore garbage\n");

    persist();

    assertSame(0, count(Http::recorded()));
    assertSame('', file_get_contents(bufferFile()), 'undeliverable lines must still be drained');
});

// ---------------------------------------------------------------------------
// drain()/requeue(): atomic clear, append-back recovery, size cap.
// ---------------------------------------------------------------------------

function callPrivate(object $obj, string $method, array $args = []): mixed
{
    $m = new ReflectionMethod($obj, $method);

    return $m->invokeArgs($obj, $args);
}

test('drain atomically reads and clears the buffer', function (): void {
    freshStorage();
    $persister = new TestablePersister;
    file_put_contents(bufferFile(), "a\nb\n");

    $content = callPrivate($persister, 'drain', [bufferFile()]);

    assertSame("a\nb\n", $content);
    assertSame('', file_get_contents(bufferFile()));
});

test('requeue appends unsent lines so concurrent writes survive', function (): void {
    freshStorage();
    $persister = new TestablePersister;
    file_put_contents(bufferFile(), "old-1\nold-2\n");

    $drained = callPrivate($persister, 'drain', [bufferFile()]);
    // A concurrent writer appends while the push is in flight.
    file_put_contents(bufferFile(), makeLine(['app' => 'x'], 'concurrent'), FILE_APPEND | LOCK_EX);
    $unsent = array_values(array_filter(explode("\n", $drained), fn ($l) => trim($l) !== ''));
    callPrivate($persister, 'requeue', [bufferFile(), $unsent]);

    $after = file_get_contents(bufferFile());
    assertTrue(str_contains($after, 'concurrent'), 'concurrent write must survive');
    assertTrue(str_contains($after, 'old-1') && str_contains($after, 'old-2'), 'unsent lines must be restored');
});

test('requeue drops unsent lines when the buffer exceeds the size cap', function (): void {
    freshStorage();
    $persister = new TestablePersister;
    file_put_contents(bufferFile(), str_repeat('x', maxBufferBytes() + 100));

    callPrivate($persister, 'requeue', [bufferFile(), ['unsent-line']]);

    assertSame(1, count($persister->errors));
    assertTrue(str_contains($persister->errors[0], 'size cap'));
    assertTrue(! str_contains(file_get_contents(bufferFile()), 'unsent-line'), 'over-cap lines must be dropped');
});

// ---------------------------------------------------------------------------
// L3Logger: locked appends with seek-to-end, safe flush/close.
// ---------------------------------------------------------------------------

test('logger handle appends a valid NDJSON line under lock', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'l3log');
    $file = fopen($path, 'a');
    $logger = newLogger($file, ['app' => 'myapp', 'type' => '{level_name}', 'junk' => ['not' => 'string']]);

    assertTrue($logger->handle(newRecord('hello', ['user' => 'bob'])));
    fclose($file);

    $raw = file_get_contents($path);
    assertTrue(str_ends_with($raw, "\n"));
    $data = json_decode(trim($raw), true);
    assertTrue(is_array($data));
    assertSame(['app' => 'myapp', 'type' => 'INFO', 'user' => 'bob'], $data['tags']);
    assertSame('[INFO] hello', $data['message']);
    assertTrue(is_numeric($data['time']));
    unlink($path);
});

test('logger appends seek to end after an external drain', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'l3log');
    $file = fopen($path, 'a');
    $logger = newLogger($file);

    $logger->handle(newRecord('first'));

    // Simulate loki:persist draining + truncating the buffer on another handle.
    $drainer = fopen($path, 'c+');
    flock($drainer, LOCK_EX);
    rewind($drainer);
    stream_get_contents($drainer);
    ftruncate($drainer, 0);
    fflush($drainer);
    flock($drainer, LOCK_UN);
    fclose($drainer);

    $logger->handle(newRecord('second'));
    fclose($file);

    $raw = file_get_contents($path);
    assertTrue(! str_contains($raw, "\0"), 'stale file offset must not leave null bytes');
    $lines = array_values(array_filter(explode("\n", $raw), fn ($l) => trim($l) !== ''));
    assertSame(1, count($lines), 'only the post-drain line should remain');
    assertSame('[INFO] second', json_decode($lines[0], true)['message']);
    unlink($path);
});

test('logger handleBatch writes every record', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'l3log');
    $file = fopen($path, 'a');
    $logger = newLogger($file);
    $logger->handleBatch([newRecord('a'), newRecord('b')]);
    fclose($file);

    assertSame(2, count(array_values(array_filter(explode("\n", file_get_contents($path)), fn ($l) => trim($l) !== ''))));
    unlink($path);
});

test('logger flush persists on force and close is idempotent', function (): void {
    $base = freshStorage();
    resetHttp();
    Http::fake(['*' => Http::response('ok', 204)]);
    file_put_contents(bufferFile(), makeLine(['app' => 'x'], 'pending'));

    $path = $base.'/writer.log';
    $file = fopen($path, 'a');
    $logger = newLogger($file);

    $logger->flush(); // no error flagged: must not touch the network
    assertSame(0, count(Http::recorded()));

    $logger->flush(true); // force: pushes the buffered line through the real persister
    assertSame(1, count(Http::recorded()));
    assertSame('', file_get_contents(bufferFile()));

    $logger->close();
    $logger->close(); // is_resource guard: second close must not throw
    unlink($path);
});

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo 'Failures: '.implode(', ', $failures)."\n";
    exit(1);
}
