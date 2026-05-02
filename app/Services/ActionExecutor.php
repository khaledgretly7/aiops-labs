<?php
namespace App\Services;

use Illuminate\Support\Facades\Log;

class ActionExecutor
{
    private array $actionLog = [];

    public function execute(string $action, array $incident): array
    {
        $startTime = microtime(true);

        $result = match($action) {
            'restart_service'    => $this->restartService($incident),
            'clear_cache'        => $this->clearCache($incident),
            'send_alert'         => $this->sendAlert($incident),
            'throttle_traffic'   => $this->throttleTraffic($incident),
            'scale_service'      => $this->scaleService($incident),
            'notify_team'        => $this->notifyTeam($incident),
            'disable_endpoint'   => $this->disableEndpoint($incident),
            'escalate_incident'  => $this->escalateIncident($incident),
            default              => $this->unknownAction($action, $incident),
        };

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $record = [
            'incident_id'  => $incident['incident_id'],
            'action_taken' => $action,
            'timestamp'    => now()->toISOString(),
            'duration_ms'  => $duration,
            'result'       => $result['status'],
            'notes'        => $result['message'],
            'details'      => $result['details'] ?? [],
        ];

        $this->actionLog[] = $record;

        Log::channel('aiops')->info('action_executed', $record);

        return $record;
    }

    // ── Actions ────────────────────────────────────────

    private function restartService(array $incident): array
    {
        // Simulate service restart
        sleep(1);
        $endpoints = implode(', ', $incident['affected_endpoints'] ?? ['unknown']);
        return [
            'status'  => 'SUCCESS',
            'message' => "Service restart simulated for endpoints: {$endpoints}",
            'details' => [
                'action'          => 'SIMULATED_RESTART',
                'affected'        => $incident['affected_endpoints'] ?? [],
                'restart_type'    => 'graceful',
                'estimated_downtime_ms' => 0,
            ],
        ];
    }

    private function clearCache(array $incident): array
    {
        // Simulate cache clear — in real system: Redis FLUSHDB or php artisan cache:clear
        $cacheKeys = ['route_cache', 'config_cache', 'view_cache'];
        return [
            'status'  => 'SUCCESS',
            'message' => 'Cache cleared: ' . implode(', ', $cacheKeys),
            'details' => [
                'action'     => 'SIMULATED_CACHE_CLEAR',
                'keys_cleared' => $cacheKeys,
                'cache_driver' => env('CACHE_DRIVER', 'file'),
            ],
        ];
    }

    private function sendAlert(array $incident): array
    {
        $severity = $incident['severity'] ?? 'MEDIUM';
        $type     = $incident['incident_type'] ?? 'UNKNOWN';
        $summary  = $incident['summary'] ?? 'No summary';

        // Simulate sending to webhook/Slack/email
        $alertPayload = [
            'incident_id'   => $incident['incident_id'],
            'incident_type' => $type,
            'severity'      => $severity,
            'summary'       => $summary,
            'timestamp'     => now()->toISOString(),
            'channel'       => config('aiops_policies.escalation_contacts.slack', '#incidents'),
        ];

        // Write alert to a file (simulating webhook delivery)
        $alertFile = storage_path('aiops/alerts.json');
        $existing  = file_exists($alertFile)
            ? json_decode(file_get_contents($alertFile), true)
            : [];
        $existing[] = $alertPayload;
        file_put_contents($alertFile, json_encode($existing, JSON_PRETTY_PRINT));

        return [
            'status'  => 'SUCCESS',
            'message' => "Alert sent for {$type} (severity: {$severity})",
            'details' => $alertPayload,
        ];
    }

    private function throttleTraffic(array $incident): array
    {
        $endpoints = $incident['affected_endpoints'] ?? [];
        $throttleRate = 50; // Reduce to 50% traffic

        return [
            'status'  => 'SUCCESS',
            'message' => "Traffic throttled to {$throttleRate}% for: " . implode(', ', $endpoints),
            'details' => [
                'action'        => 'SIMULATED_THROTTLE',
                'endpoints'     => $endpoints,
                'throttle_pct'  => $throttleRate,
                'duration_sec'  => 300,
                'rule'          => "rate_limit=>{$throttleRate}%",
            ],
        ];
    }

    private function scaleService(array $incident): array
    {
        $currentInstances = 1;
        $targetInstances  = 3;

        return [
            'status'  => 'SUCCESS',
            'message' => "Service scaled from {$currentInstances} to {$targetInstances} instances",
            'details' => [
                'action'            => 'SIMULATED_SCALE_OUT',
                'current_instances' => $currentInstances,
                'target_instances'  => $targetInstances,
                'trigger'           => $incident['incident_type'],
                'estimated_ready_sec' => 30,
            ],
        ];
    }

    private function notifyTeam(array $incident): array
    {
        $contacts = config('aiops_policies.escalation_contacts');
        $message  = "[AIOPS ALERT] {$incident['incident_type']} detected. "
                  . "Severity: {$incident['severity']}. "
                  . "ID: {$incident['incident_id']}";

        return [
            'status'  => 'SUCCESS',
            'message' => "Team notified via email and Slack (simulated)",
            'details' => [
                'action'    => 'SIMULATED_NOTIFY',
                'channels'  => ['email' => $contacts['email'], 'slack' => $contacts['slack']],
                'message'   => $message,
                'recipients'=> ['on-call-engineer', 'team-lead'],
            ],
        ];
    }

    private function disableEndpoint(array $incident): array
    {
        $endpoints = $incident['affected_endpoints'] ?? [];
        return [
            'status'  => 'SUCCESS',
            'message' => "Endpoint(s) disabled: " . implode(', ', $endpoints),
            'details' => [
                'action'     => 'SIMULATED_DISABLE',
                'endpoints'  => $endpoints,
                'method'     => 'circuit_breaker',
                'retry_after'=> 60,
            ],
        ];
    }

    private function escalateIncident(array $incident): array
    {
        $escalationRecord = [
            'incident_id'       => $incident['incident_id'],
            'escalation_level'  => 'CRITICAL',
            'escalated_at'      => now()->toISOString(),
            'escalated_to'      => 'senior-oncall-engineer',
            'reason'            => 'Automated actions insufficient — manual intervention required',
            'incident_type'     => $incident['incident_type'],
            'severity'          => $incident['severity'],
        ];

        $escalationFile = storage_path('aiops/escalations.json');
        $existing = file_exists($escalationFile)
            ? json_decode(file_get_contents($escalationFile), true)
            : [];
        $existing[] = $escalationRecord;
        file_put_contents($escalationFile, json_encode($existing, JSON_PRETTY_PRINT));

        return [
            'status'  => 'ESCALATED',
            'message' => 'Incident escalated to CRITICAL — senior engineer notified',
            'details' => $escalationRecord,
        ];
    }

    private function unknownAction(string $action, array $incident): array
    {
        return [
            'status'  => 'FAILED',
            'message' => "Unknown action: {$action}",
            'details' => ['incident_id' => $incident['incident_id']],
        ];
    }

    public function getLog(): array
    {
        return $this->actionLog;
    }
}