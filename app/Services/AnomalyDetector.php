<?php
namespace App\Services;

class AnomalyDetector
{
    private array $baselines = [];
    private string $baselineFile;

    public function __construct()
    {
        $this->baselineFile = storage_path('aiops/baselines.json');
        $this->loadBaselines();
    }

    private function loadBaselines(): void
    {
        if (file_exists($this->baselineFile)) {
            $this->baselines = json_decode(file_get_contents($this->baselineFile), true) ?? [];
        }
    }

    private function saveBaselines(): void
    {
        file_put_contents($this->baselineFile, json_encode($this->baselines, JSON_PRETTY_PRINT));
    }

    public function updateBaseline(string $endpoint, string $metric, float $value): void
    {
        if (!isset($this->baselines[$endpoint][$metric])) {
            $this->baselines[$endpoint][$metric] = [
                'samples' => [],
                'avg'     => $value,
            ];
        }

        $samples   = &$this->baselines[$endpoint][$metric]['samples'];
        $samples[] = $value;

        // Keep last 100 samples
        if (count($samples) > 100) {
            array_shift($samples);
        }

        $this->baselines[$endpoint][$metric]['avg'] = array_sum($samples) / count($samples);
        $this->saveBaselines();
    }

    public function getBaseline(string $endpoint, string $metric): float
    {
        return $this->baselines[$endpoint][$metric]['avg'] ?? 0.0;
    }

    public function detectAnomalies(array $currentMetrics): array
    {
        $anomalies = [];

        foreach ($currentMetrics as $endpoint => $metrics) {

            // Latency anomaly: current > 3x baseline
            $baselineLatency = $this->getBaseline($endpoint, 'latency_p95');
            $currentLatency  = $metrics['latency_p95'] ?? 0;
            if ($baselineLatency > 0 && $currentLatency > ($baselineLatency * 3)) {
                $anomalies[] = [
                    'type'      => 'LATENCY_ANOMALY',
                    'endpoint'  => $endpoint,
                    'baseline'  => $baselineLatency,
                    'observed'  => $currentLatency,
                    'ratio'     => round($currentLatency / $baselineLatency, 2),
                ];
            }

            // Error rate anomaly: > 10%
            $currentErrorRate = $metrics['error_rate'] ?? 0;
            $baselineErrorRate = $this->getBaseline($endpoint, 'error_rate');
            if ($currentErrorRate > 0.10) {
                $anomalies[] = [
                    'type'      => 'ERROR_RATE_ANOMALY',
                    'endpoint'  => $endpoint,
                    'baseline'  => $baselineErrorRate,
                    'observed'  => $currentErrorRate,
                    'ratio'     => $baselineErrorRate > 0 ? round($currentErrorRate / $baselineErrorRate, 2) : 999,
                ];
            }

            // Traffic anomaly: > 2x baseline
            $baselineRate   = $this->getBaseline($endpoint, 'request_rate');
            $currentRate    = $metrics['request_rate'] ?? 0;
            if ($baselineRate > 0 && $currentRate > ($baselineRate * 2)) {
                $anomalies[] = [
                    'type'     => 'TRAFFIC_ANOMALY',
                    'endpoint' => $endpoint,
                    'baseline' => $baselineRate,
                    'observed' => $currentRate,
                    'ratio'    => round($currentRate / $baselineRate, 2),
                ];
            }
        }

        return $anomalies;
    }
}