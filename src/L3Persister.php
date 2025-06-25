<?php

namespace Devcake\LaravelLokiLogging;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

class L3Persister extends Command
{
    protected $signature = 'loki:persist';
    protected $description = 'Persist recent log messages to loki';

    public function handle(): void
    {
        $file = storage_path(L3ServiceProvider::LOG_LOCATION);
        if (!file_exists($file)) return;

        $content = file_get_contents($file);
        file_put_contents($file, '');

        $messages = explode("\n", $content);
        if (count($messages) === 0) return;

        $path = config('l3.loki.server') . "/loki/api/v1/push";

        Http::pool(fn (Pool $pool) => array_map(function ($message) use ($pool, $path) {
            $data = json_decode($message);
            if ($data === null) return null;

            return $pool->as('loki_' . uniqid())->withBasicAuth(
                config('l3.loki.username'),
                config('l3.loki.password')
            )->post($path, [
                'streams' => [[
                    'stream' => $data->tags,
                    'values' => [[
                        strval($data->time * 1000),
                        $data->message,
                    ]]
                ]]
            ]);
        }, $messages));
    }
}
