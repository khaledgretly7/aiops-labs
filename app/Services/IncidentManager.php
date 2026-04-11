<?php
namespace App\Services;

use Illuminate\Support\Str;

class IncidentManager
{
    private string $incidentsFile;
    private array  $activeIncidents = [];

    public function __construct()
    {
        $this->incidentsFile = storage_path('aiops/incidents.json');
        $this->loadIncidents();
    }

    private function loadIncidents(): void
    {
        if (file_exists($this->incidentsFile)) {
            $this->activeIncidents = json_decode(
                file_get_contents($this->incidentsFile), true
            ) ?? [];
        }
    }

    private function saveIncidents(): void
    {
        file_put_contents(
            $this->incidentsFile,
            json_encode($this->activeIncidents, JSON_PRETTY_PRINT)
        );
    }

    public function correlate(array $anomalies, array $currentMetrics, array $baselines): ?array
    {
        if (empty($anomalies)) return null;

        $types     = array_column($anomalies, 'type');
        $endpoints = array_unique(array_column($anomalies, 'endpoint'));

        // Determine incident type from signal combination
        $incidentType = $this->determineIncidentType($types, $anomalies);
        $severity     = $this->determineSeverity($anomalies);

        // Deduplication: check if same incident type is already active
        foreach ($this->activeIncidents as &$existing) {
            if (
                $existing['status'] === 'OPEN' &&
                $existing['incident_type'] === $incidentType &&
                $existing['affected_endpoints'] === $endpoints
            ) {
                // Already exists — suppress duplicate alert
                $existing['last_seen'] = now()->toISOString();
                $this->saveIncidents();
                return null;
            }
        }

        $incident = [
            'incident_id'        => 'INC-' . strtoupper(Str::random(8)),
            'incident_type'      => $incidentType,
            'severity'           => $severity,
            'status'             => 'OPEN',
            'detected_at'        => now()->toISOString(),
            'last_seen'          => now()->toISOString(),
            'affected_service'   => 'aiops-laravel',
            'affected_endpoints' => $endpoints,
            'triggering_signals' => $anomalies,
            'baseline_values'    => $baselines,
            'observed_values'    => $currentMetrics,
            'summary'            => $this->buildSummary($incidentType, $anomalies, $endpoints),
        ];

        $this->activeIncidents[] = $incident;
        $this->saveIncidents();

        return $incident;
    }

    private function determineIncidentType(array $types, array $anomalies): string
    {
        $hasLatency  = in_array('LATENCY_ANOMALY', $types);
        $hasError    = in_array('ERROR_RATE_ANOMALY', $types);
        $hasTraffic  = in_array('TRAFFIC_ANOMALY', $types);
        $endpoints   = array_unique(array_column($anomalies, 'endpoint'));

        if ($hasError && $hasLatency)    return 'SERVICE_DEGRADATION';
        if ($hasError && count($endpoints) === 1) return 'LOCALIZED_ENDPOINT_FAILURE';
        if ($hasError)                   return 'ERROR_STORM';
        if ($hasLatency)                 return 'LATENCY_SPIKE';
        if ($hasTraffic)                 return 'TRAFFIC_SURGE';
        return 'SERVICE_DEGRADATION';
    }

    private function determineSeverity(array $anomalies): string
    {
        $maxRatio = max(array_column($anomalies, 'ratio'));
        if ($maxRatio > 10)  return 'CRITICAL';
        if ($maxRatio > 5)   return 'HIGH';
        if ($maxRatio > 2)   return 'MEDIUM';
        return 'LOW';
    }

    private function buildSummary(string $type, array $anomalies, array $endpoints): string
    {
        $endpointList = implode(', ', $endpoints);
        $count        = count($anomalies);
        return "{$type} detected on [{$endpointList}] with {$count} abnormal signal(s). "
             . "Triggered by: " . implode(', ', array_unique(array_column($anomalies, 'type')));
    }

    public function getActiveIncidents(): array
    {
        return array_filter($this->activeIncidents, fn($i) => $i['status'] === 'OPEN');
    }
}