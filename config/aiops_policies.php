<?php
return [
    'policies' => [
        'LATENCY_SPIKE' => [
            'actions'          => ['restart_service', 'clear_cache', 'notify_team'],
            'escalate_after'   => 2,
            'escalation_level' => 'HIGH',
            'description'      => 'High latency detected — restart service and clear cache',
        ],
        'ERROR_STORM' => [
            'actions'          => ['send_alert', 'throttle_traffic', 'notify_team'],
            'escalate_after'   => 2,
            'escalation_level' => 'CRITICAL',
            'description'      => 'Error storm — throttle traffic and alert team',
        ],
        'SERVICE_DEGRADATION' => [
            'actions'          => ['send_alert', 'restart_service', 'escalate_incident'],
            'escalate_after'   => 1,
            'escalation_level' => 'CRITICAL',
            'description'      => 'Service degradation — immediate escalation',
        ],
        'TRAFFIC_SURGE' => [
            'actions'          => ['scale_service', 'throttle_traffic', 'notify_team'],
            'escalate_after'   => 3,
            'escalation_level' => 'MEDIUM',
            'description'      => 'Traffic surge — scale and throttle',
        ],
        'LOCALIZED_ENDPOINT_FAILURE' => [
            'actions'          => ['disable_endpoint', 'send_alert', 'notify_team'],
            'escalate_after'   => 2,
            'escalation_level' => 'HIGH',
            'description'      => 'Endpoint failure — disable and alert',
        ],
        'DEFAULT' => [
            'actions'          => ['send_alert', 'notify_team'],
            'escalate_after'   => 3,
            'escalation_level' => 'MEDIUM',
            'description'      => 'Unknown incident — send alert',
        ],
    ],

    'escalation_contacts' => [
        'email'   => 'oncall@aiops-lab.com',
        'slack'   => '#incidents',
        'webhook' => 'http://localhost:9999/webhook',
    ],

    'thresholds' => [
        'max_retries'        => 3,
        'retry_delay_seconds'=> 5,
        'escalation_timeout' => 120,
    ],
];