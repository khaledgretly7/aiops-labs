<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class PrometheusClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('PROMETHEUS_URL', 'http://localhost:9090');
    }

    public function query(string $promql): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/v1/query", [
                'query' => $promql
            ]);

            if ($response->successful()) {
                return $response->json('data.result') ?? [];
            }
        } catch (\Exception $e) {
            // ignore
        }
        return [];
    }

    public function getRequestRate(): array
    {
        return $this->query('rate(http_requests_total[2m])');
    }

    public function getErrorRate(): array
    {
        return $this->query(
            'rate(http_requests_total{status=~"4..|5.."}[2m]) / rate(http_requests_total[2m])'
        );
    }

    public function getLatencyP50(): array
    {
        return $this->query('histogram_quantile(0.50, rate(http_request_duration_seconds_bucket[2m]))');
    }

    public function getLatencyP95(): array
    {
        return $this->query('histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[2m]))');
    }

    public function getLatencyP99(): array
    {
        return $this->query('histogram_quantile(0.99, rate(http_request_duration_seconds_bucket[2m]))');
    }

    public function getErrorCategories(): array
    {
        return $this->query('rate(http_errors_total[2m])');
    }
}