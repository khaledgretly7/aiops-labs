<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ActionExecutor;

class RespondToIncidents extends Command
{
    protected $signature   = 'aiops:respond';
    protected $description = 'Automated incident response engine';

    private string $incidentsFile;
    private string $responsesFile;
    private array  $processedIds = [];

    public function handle(): void
    {
        $this->incidentsFile = storage_path('aiops/incidents.json');
        $this->responsesFile = storage_path('aiops/responses.json');

        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║   AIOps Automated Response Engine        ║');
        $this->info('║   Monitoring incidents every 20 seconds  ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->line('');

        // Load already-processed incident IDs
        $this->processedIds = $this->loadProcessedIds();

        while (true) {
            $this->line("\n[" . now() . "] Checking for new incidents...");

            $incidents = $this->loadIncidents();

            if (empty($incidents)) {
                $this->line("  No incidents found in incidents.json");
                $this->line("  Run 'php artisan aiops:detect' first to generate incidents.");
            } else {
                $newIncidents = array_filter($incidents, function ($i) {
                    return !in_array($i['incident_id'], $this->processedIds);
                });

                if (empty($newIncidents)) {
                    $this->info("  All " . count($incidents) . " incident(s) already processed.");
                } else {
                    $this->warn("  Found " . count($newIncidents) . " new incident(s) to process!");
                    foreach ($newIncidents as $incident) {
                        $this->processIncident($incident);
                    }
                }
            }

            // Also check for persisting incidents that need escalation
            $this->checkEscalations($incidents ?? []);

            $this->line(str_repeat('─', 50));
            sleep(20);
        }
    }

    private function processIncident(array $incident): void
    {
        $incidentId   = $incident['incident_id'];
        $incidentType = $incident['incident_type'];
        $severity     = $incident['severity'];

        $this->line('');
        $this->error("  ► Processing Incident: {$incidentId}");
        $this->line("    Type:     {$incidentType}");
        $this->line("    Severity: {$severity}");
        $this->line("    Summary:  " . ($incident['summary'] ?? 'N/A'));

        // Get policy for this incident type
        $policies = config('aiops_policies.policies');
        $policy   = $policies[$incidentType] ?? $policies['DEFAULT'];
        $actions  = $policy['actions'];

        $this->line("\n    Policy: " . $policy['description']);
        $this->line("    Actions: " . implode(' → ', $actions));

        $executor    = new ActionExecutor();
        $allResponses = [];
        $failCount    = 0;

        foreach ($actions as $action) {
            $this->line("\n    Executing: [{$action}]...");
            $record = $executor->execute($action, $incident);

            if ($record['result'] === 'SUCCESS') {
                $this->info("      ✓ {$record['result']}: {$record['notes']}");
            } elseif ($record['result'] === 'ESCALATED') {
                $this->warn("      ⚠ {$record['result']}: {$record['notes']}");
            } else {
                $this->error("      ✗ {$record['result']}: {$record['notes']}");
                $failCount++;
            }

            $allResponses[] = $record;
            sleep(1); // Simulate action execution time
        }

        // Escalation logic
        $escalateAfter = $policy['escalate_after'] ?? 3;
        if ($failCount >= $escalateAfter || $severity === 'CRITICAL') {
            $this->triggerEscalation($incident, $policy, $failCount, $executor, $allResponses);
        }

        // Save all responses
        $this->saveResponses($allResponses);

        // Mark as processed
        $this->processedIds[] = $incidentId;
        $this->saveProcessedIds($this->processedIds);

        $this->info("\n    ✓ Incident {$incidentId} response complete.");
    }

    private function triggerEscalation(
        array $incident,
        array $policy,
        int $failCount,
        ActionExecutor $executor,
        array &$allResponses
    ): void {
        $this->error("\n    *** ESCALATING TO " . $policy['escalation_level'] . " ***");
        $this->error("    Reason: {$failCount} action(s) failed or severity is CRITICAL");

        $escalationIncident = array_merge($incident, [
            'severity' => $policy['escalation_level'],
        ]);

        $escalationRecord = $executor->execute('escalate_incident', $escalationIncident);
        $allResponses[]   = $escalationRecord;

        $this->error("    CRITICAL_ALERT: Manual intervention required for {$incident['incident_id']}");
    }

    private function checkEscalations(array $incidents): void
    {
        foreach ($incidents as $incident) {
            if (
                ($incident['status'] ?? '') === 'OPEN' &&
                ($incident['severity'] ?? '') === 'CRITICAL' &&
                in_array($incident['incident_id'], $this->processedIds)
            ) {
                $detectedAt = strtotime($incident['detected_at'] ?? 'now');
                $ageSeconds = time() - $detectedAt;
                $timeout    = config('aiops_policies.thresholds.escalation_timeout', 120);

                if ($ageSeconds > $timeout) {
                    $this->error(
                        "  ESCALATION CHECK: {$incident['incident_id']} still OPEN " .
                        "after {$ageSeconds}s — re-escalating!"
                    );
                }
            }
        }
    }

    // ── Helpers ───────────────────────────────────────

    private function loadIncidents(): array
    {
        if (!file_exists($this->incidentsFile)) return [];
        return json_decode(file_get_contents($this->incidentsFile), true) ?? [];
    }

    private function saveResponses(array $newResponses): void
    {
        $existing = [];
        if (file_exists($this->responsesFile)) {
            $existing = json_decode(file_get_contents($this->responsesFile), true) ?? [];
        }
        $merged = array_merge($existing, $newResponses);
        file_put_contents($this->responsesFile, json_encode($merged, JSON_PRETTY_PRINT));
    }

    private function loadProcessedIds(): array
    {
        $file = storage_path('aiops/processed_ids.json');
        if (!file_exists($file)) return [];
        return json_decode(file_get_contents($file), true) ?? [];
    }

    private function saveProcessedIds(array $ids): void
    {
        file_put_contents(
            storage_path('aiops/processed_ids.json'),
            json_encode($ids, JSON_PRETTY_PRINT)
        );
    }
}