<?php

declare(strict_types=1);

namespace App\Observability;

final readonly class MetricsRegistry
{
    private const METRICS = [
        'worker_started',
        'worker_stopped',
        'worker_message_failed',
        'webhook_received',
        'webhook_failed',
        'reservation_failed',
    ];

    public function __construct(private string $file) {}

    public function increment(string $metric, string $tenant = 'app'): void
    {
        if (!\in_array($metric, self::METRICS, true) || !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $tenant)) {
            return;
        }
        $directory = dirname($this->file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, \LOCK_EX)) {
                return;
            }
            $contents = stream_get_contents($handle);
            $values = \is_string($contents) ? json_decode($contents, true) : [];
            $values = \is_array($values) ? $values : [];
            $key = $metric.'|'.$tenant;
            $values[$key] = (int) ($values[$key] ?? 0) + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($values, \JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    public function render(): string
    {
        $values = is_file($this->file) ? json_decode((string) @file_get_contents($this->file), true) : [];
        $values = \is_array($values) ? $values : [];
        ksort($values);
        $lines = [];
        foreach (self::METRICS as $metric) {
            $lines[] = '# TYPE todatempo_'.$metric.'_total counter';
        }
        foreach ($values as $key => $value) {
            [$metric, $tenant] = explode('|', (string) $key, 2);
            $lines[] = sprintf('todatempo_%s_total{tenant="%s"} %d', $metric, $tenant, (int) $value);
        }

        return implode("\n", $lines)."\n";
    }
}
