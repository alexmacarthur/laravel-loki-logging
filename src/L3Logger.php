<?php

namespace AlexMacArthur\LaravelLokiLogging;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;

class L3Logger implements HandlerInterface
{
    /** @var resource|\fopen */
    private mixed $file;

    private bool $hasError = false;

    private array $context;

    private string $format;

    private ConfigRepository $config;

    private Application $app;

    public function __construct()
    {
        $this->config = \app('config');
        $this->app = \app();

        $this->format = $this->config->get('l3.format');
        $this->context = $this->config->get('l3.context');

        $file = $this->app->storagePath().'/'.L3ServiceProvider::LOG_LOCATION;
        if (! file_exists($file)) {
            $dir = dirname($file);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            touch($file);
        }
        $this->file = fopen($file, 'a');
        register_shutdown_function([$this, 'flush']);
    }

    /**
     * This handler is capable of handling every record
     */
    public function isHandling(LogRecord $record): bool
    {
        return true;
    }

    public function handle(LogRecord $record): bool
    {
        $this->hasError = $this->hasError || $record->level->name === 'ERROR';
        $recordArray = $record->toArray();
        $message = $this->formatString($this->format, $recordArray);
        $tags = array_merge($this->context, $recordArray['context']);
        foreach ($tags as $tag => $value) {
            if (is_string($value)) {
                $tags[$tag] = $this->formatString($value, $recordArray);
            } else {
                unset($tags[$tag]);
            }
        }

        return (bool) fwrite($this->file, json_encode([
            'time' => (int) (microtime(true) * 1000000),
            'tags' => $tags,
            'message' => $message,
        ])."\n");
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            if ($record instanceof LogRecord) {
                $this->handle($record);
            }
        }
    }

    public function flush(bool $force = false): void
    {
        if ($this->hasError || $force) {
            $persister = new L3Persister;
            $persister->handle();
        }
    }

    public function close(): void
    {
        fclose($this->file);
    }

    private function formatString(string $format, array $context): string
    {
        $message = $format;
        foreach ($context as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $message = str_replace(
                sprintf('{%s}', $key),
                $value,
                $message
            );
        }

        return $message;
    }
}
