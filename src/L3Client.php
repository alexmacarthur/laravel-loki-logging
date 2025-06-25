<?php

namespace Devcake\LaravelLokiLogging;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class L3Client
{
    private PendingRequest $http;
    private string $path;

    public function __construct()
    {
        $this->http = Http::withBasicAuth(
            config('l3.loki.username'),
            config('l3.loki.password')
        );

        $this->path = config('l3.loki.server') . "/loki/api/v1/push";
    }

    public function time(): string {
        return strval((int)(microtime(true) * 1000000000));
    }

    public function log(array $messages, array $tags = [])
    {
        return $this->http->post($this->path, [
            'streams' => [[
                'stream' => $tags,
                'values' => $messages
            ]]
        ]);
    }
}
