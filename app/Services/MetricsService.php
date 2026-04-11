<?php
namespace App\Services;

class MetricsService
{
    private static string $file = '';
    private static array $buckets = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];

    private static function getFile(): string
    {
        if (!self::$file) {
            self::$file = storage_path('logs/metrics.json');
        }
        return self::$file;
    }

    private static function load(): array
    {
        $file = self::getFile();
        if (!file_exists($file)) {
            return ['requests' => [], 'errors' => [], 'buckets' => [], 'sum' => [], 'count' => []];
        }
        return json_decode(file_get_contents($file), true) ?? ['requests' => [], 'errors' => [], 'buckets' => [], 'sum' => [], 'count' => []];
    }

    private static function save(array $data): void
    {
        file_put_contents(self::getFile(), json_encode($data), LOCK_EX);
    }

    public static function record(string $method, string $path, int $status, float $latencySeconds, ?string $errorCategory): void
    {
        $data = self::load();

        $key = "{$method}|{$path}|{$status}";
        $data['requests'][$key] = ($data['requests'][$key] ?? 0) + 1;

        if ($errorCategory) {
            $eKey = "{$method}|{$path}|{$errorCategory}";
            $data['errors'][$eKey] = ($data['errors'][$eKey] ?? 0) + 1;
        }

        $dKey = "{$method}|{$path}";
        $data['sum'][$dKey]   = ($data['sum'][$dKey] ?? 0) + $latencySeconds;
        $data['count'][$dKey] = ($data['count'][$dKey] ?? 0) + 1;

        foreach (self::$buckets as $le) {
            $bKey = "{$dKey}|{$le}";
            if ($latencySeconds <= $le) {
                $data['buckets'][$bKey] = ($data['buckets'][$bKey] ?? 0) + 1;
            }
        }
        $infKey = "{$dKey}|+Inf";
        $data['buckets'][$infKey] = ($data['buckets'][$infKey] ?? 0) + 1;

        self::save($data);
    }

    public static function render(): string
    {
        $data = self::load();
        $out  = '';

        $out .= "# HELP http_requests_total Total HTTP requests\n";
        $out .= "# TYPE http_requests_total counter\n";
        foreach ($data['requests'] as $key => $count) {
            [$method, $path, $status] = explode('|', $key);
            $out .= "http_requests_total{method=\"{$method}\",path=\"{$path}\",status=\"{$status}\"} {$count}\n";
        }

        $out .= "# HELP http_errors_total Total HTTP errors by category\n";
        $out .= "# TYPE http_errors_total counter\n";
        foreach ($data['errors'] as $key => $count) {
            [$method, $path, $category] = explode('|', $key);
            $out .= "http_errors_total{method=\"{$method}\",path=\"{$path}\",error_category=\"{$category}\"} {$count}\n";
        }

        $out .= "# HELP http_request_duration_seconds Request duration histogram\n";
        $out .= "# TYPE http_request_duration_seconds histogram\n";
        foreach ($data['count'] as $dKey => $count) {
            [$method, $path] = explode('|', $dKey);
            foreach (self::$buckets as $le) {
                $bKey = "{$dKey}|{$le}";
                $bVal = $data['buckets'][$bKey] ?? 0;
                $out .= "http_request_duration_seconds_bucket{method=\"{$method}\",path=\"{$path}\",le=\"{$le}\"} {$bVal}\n";
            }
            $infVal = $data['buckets']["{$dKey}|+Inf"] ?? 0;
            $out .= "http_request_duration_seconds_bucket{method=\"{$method}\",path=\"{$path}\",le=\"+Inf\"} {$infVal}\n";
            $out .= "http_request_duration_seconds_sum{method=\"{$method}\",path=\"{$path}\"} ".($data['sum'][$dKey] ?? 0)."\n";
            $out .= "http_request_duration_seconds_count{method=\"{$method}\",path=\"{$path}\"} {$count}\n";
        }

        return $out;
    }
}