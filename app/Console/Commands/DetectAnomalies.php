<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PrometheusClient;
use App\Services\AnomalyDetector;
use App\Services\IncidentManager;

class DetectAnomalies extends Command
{
    protected $signature   = 'aiops:detect';
    protected $description = 'Continuously detect anomalies from Prometheus metrics';

    public function handle(): void
    {
        $prometheus = new PrometheusClient();
        $detector   = new AnomalyDetector();
        $incidents  = new IncidentManager();

        $this->info('AIOps Detection Engine started. Polling every 25 seconds...');
        $this->info('Press Ctrl+C to stop.');
        $this->line(str_repeat('-', 60));

        while (true) {
            $this->line("\n[" . now() . "] Running detection cycle...");

            // Fetch metrics from Prometheus
            $requestRates  = $prometheus->getRequestRate();
            $errorRates    = $prometheus->getErrorRate();
            $latencyP95    = $prometheus->getLatencyP95();
            $errorCats     = $prometheus->getErrorCategories();

            // Build per-endpoint metrics map
            $currentMetrics = [];
            $baselines      = [];

            foreach ($requestRates as $result) {
                $path = $result['metric']['path'] ?? 'unknown';
                $val  = (float)($result['value'][1] ?? 0);
                $currentMetrics[$path]['request_rate'] = $val;
                $detector->updateBaseline($path, 'request_rate', $val);
                $baselines[$path]['request_rate'] = $detector->getBaseline($path, 'request_rate');
            }

            foreach ($errorRates as $result) {
                $path = $result['metric']['path'] ?? 'unknown';
                $val  = (float)($result['value'][1] ?? 0);
                $currentMetrics[$path]['error_rate'] = $val;
                $detector->updateBaseline($path, 'error_rate', $val);
                $baselines[$path]['error_rate'] = $detector->getBaseline($path, 'error_rate');
            }

            foreach ($latencyP95 as $result) {
                $path = $result['metric']['path'] ?? 'unknown';
                $val  = (float)($result['value'][1] ?? 0);
                $currentMetrics[$path]['latency_p95'] = $val;
                $detector->updateBaseline($path, 'latency_p95', $val);
                $baselines[$path]['latency_p95'] = $detector->getBaseline($path, 'latency_p95');
            }

            // Print current metrics
            $this->line("Current metrics:");
            foreach ($currentMetrics as $path => $metrics) {
                $rr  = round($metrics['request_rate'] ?? 0, 4);
                $er  = round(($metrics['error_rate'] ?? 0) * 100, 2);
                $lat = round($metrics['latency_p95'] ?? 0, 4);
                $this->line("  {$path} | req_rate={$rr} | error_rate={$er}% | p95_latency={$lat}s");
            }

            // Detect anomalies
            $anomalies = $detector->detectAnomalies($currentMetrics);

            if (empty($anomalies)) {
                $this->info("  No anomalies detected.");
            } else {
                $this->warn("  ANOMALIES DETECTED: " . count($anomalies));
                foreach ($anomalies as $a) {
                    $this->warn("    [{$a['type']}] {$a['endpoint']} | observed={$a['observed']} baseline={$a['baseline']} ratio={$a['ratio']}x");
                }

                // Correlate into incident
                $incident = $incidents->correlate($anomalies, $currentMetrics, $baselines);

                if ($incident) {
                    $this->error("\n  *** INCIDENT CREATED ***");
                    $this->error("  ID:       {$incident['incident_id']}");
                    $this->error("  Type:     {$incident['incident_type']}");
                    $this->error("  Severity: {$incident['severity']}");
                    $this->error("  Summary:  {$incident['summary']}");
                    $this->line("");

                    // JSON alert output
                    $alert = [
                        'incident_id'   => $incident['incident_id'],
                        'incident_type' => $incident['incident_type'],
                        'severity'      => $incident['severity'],
                        'timestamp'     => $incident['detected_at'],
                        'summary'       => $incident['summary'],
                    ];
                    $this->line("  ALERT JSON: " . json_encode($alert));
                } else {
                    $this->warn("  Incident already active — alert suppressed.");
                }
            }

            $this->line(str_repeat('-', 60));
            sleep(25);
        }
    }
}