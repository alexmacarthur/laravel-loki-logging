<?php

namespace AlexMacArthur\LaravelLokiLogging;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class L3Persister extends Command
{
    protected $signature = 'loki:persist';

    protected $description = 'Persist recent log messages to loki';

    private const CHUNK_SIZE = 1000;

    private const REQUEST_TIMEOUT = 10;

    private const MAX_BUFFER_BYTES = 5000000;

    public function handle(): void
    {
        $server = config('l3.loki.server');
        if (empty($server)) {
            $this->error('L3Persister: LOG_SERVER is not configured, cannot push to Loki.');

            return;
        }

        $file = storage_path(L3ServiceProvider::LOG_LOCATION);
        if (! file_exists($file)) {
            return;
        }

        $lines = array_values(array_filter(
            explode("\n", $this->drain($file)),
            fn ($line) => trim($line) !== ''
        ));

        if (count($lines) === 0) {
            return;
        }

        $path = rtrim($server, '/').'/loki/api/v1/push';
        $chunks = array_chunk($lines, self::CHUNK_SIZE);

        foreach ($chunks as $index => $chunk) {
            $streams = self::groupIntoStreams($chunk);
            if (count($streams) === 0) {
                continue;
            }

            try {
                $response = Http::withBasicAuth(
                    (string) config('l3.loki.username', ''),
                    (string) config('l3.loki.password', '')
                )
                    ->timeout(self::REQUEST_TIMEOUT)
                    ->connectTimeout(5)
                    ->retry(2, 200)
                    ->post($path, ['streams' => $streams]);

                if ($response->failed()) {
                    $this->requeue($file, array_merge(...array_slice($chunks, $index)));
                    $this->error('L3Persister: Failed to push logs to Loki. Status: '.$response->status());

                    return;
                }
            } catch (\Throwable $e) {
                $this->requeue($file, array_merge(...array_slice($chunks, $index)));
                $this->error('L3Persister: Exception while pushing logs to Loki. '.$e->getMessage());

                return;
            }
        }
    }

    /**
     * Group raw buffer lines into Loki streams, one stream per label set.
     *
     * Pure (no I/O) so the batching logic stays testable without Laravel.
     *
     * @param  array<int, string>  $lines
     * @return array<int, array{stream: array<string, string>, values: array<int, array{0: string, 1: string}>}>
     */
    public static function groupIntoStreams(array $lines): array
    {
        $grouped = [];

        foreach ($lines as $line) {
            $data = json_decode(trim($line), true);
            if (! is_array($data)) {
                continue;
            }

            $timestamp = isset($data['time']) && is_numeric($data['time'])
                ? strval((int) ((float) $data['time'] * 1000))
                : strval((int) (microtime(true) * 1000000000));

            $tags = isset($data['tags']) && is_array($data['tags']) ? $data['tags'] : [];
            $labels = [];
            foreach ($tags as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $labels[(string) $key] = strval($value);
                }
            }
            ksort($labels);

            $message = isset($data['message']) ? strval($data['message']) : trim($line);

            $fingerprint = json_encode($labels);
            if (! isset($grouped[$fingerprint])) {
                $grouped[$fingerprint] = ['stream' => $labels, 'values' => []];
            }
            $grouped[$fingerprint]['values'][] = [$timestamp, $message];
        }

        return array_values($grouped);
    }

    /**
     * Atomically read and clear the buffer under an exclusive lock.
     */
    private function drain(string $file): string
    {
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return '';
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            return '';
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        ftruncate($handle, 0);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $content;
    }

    /**
     * Append unsent lines back to the buffer so a failed push is retried,
     * not silently dropped. Never reports via Log to avoid a feedback loop.
     *
     * @param  array<int, string>  $lines
     */
    private function requeue(string $file, array $lines): void
    {
        if (count($lines) === 0) {
            return;
        }

        clearstatcache(true, $file);
        if (file_exists($file) && filesize($file) > self::MAX_BUFFER_BYTES) {
            $this->error('L3Persister: buffer exceeds size cap, dropping '.count($lines).' unsent lines.');

            return;
        }

        file_put_contents($file, implode("\n", $lines)."\n", FILE_APPEND | LOCK_EX);
    }
}
