<?php

return [
    'window_days' => max(1, (int) env('OPERATIONS_METRICS_WINDOW_DAYS', 30)),

    'thresholds' => [
        'scheduler_heartbeat_seconds' => max(60, (int) env('OPERATIONS_SCHEDULER_HEARTBEAT_SECONDS', 180)),
        'worker_heartbeat_seconds' => max(60, (int) env('OPERATIONS_WORKER_HEARTBEAT_SECONDS', 180)),
        'artifact_prune_hours' => max(1, (int) env('OPERATIONS_ARTIFACT_PRUNE_HOURS', 48)),
        'cookie_prune_hours' => max(1, (int) env('OPERATIONS_COOKIE_PRUNE_HOURS', 48)),
        'oldest_pending_job_seconds' => max(30, (int) env('OPERATIONS_OLDEST_JOB_SECONDS', 120)),
        'queue_lag_p95_seconds' => max(1, (int) env('OPERATIONS_QUEUE_LAG_P95_SECONDS', 30)),
        'signal_to_start_p95_seconds' => max(1, (int) env('OPERATIONS_SIGNAL_TO_START_P95_SECONDS', 3)),
        'workflow_success_rate_percent' => max(0, min(100, (int) env('OPERATIONS_WORKFLOW_SUCCESS_RATE_PERCENT', 80))),
        'workflow_success_minimum_runs' => max(1, (int) env('OPERATIONS_WORKFLOW_SUCCESS_MINIMUM_RUNS', 10)),
        'task_type_success_rate_percent' => max(0, min(100, (int) env('OPERATIONS_TASK_TYPE_SUCCESS_RATE_PERCENT', 75))),
        'task_type_success_minimum_runs' => max(1, (int) env('OPERATIONS_TASK_TYPE_SUCCESS_MINIMUM_RUNS', 10)),
        'portal_success_rate_percent' => max(0, min(100, (int) env('OPERATIONS_PORTAL_SUCCESS_RATE_PERCENT', 75))),
        'portal_success_minimum_attempts' => max(1, (int) env('OPERATIONS_PORTAL_SUCCESS_MINIMUM_ATTEMPTS', 10)),
        'enrollment_alert_window_minutes' => max(1, (int) env('OPERATIONS_ENROLLMENT_ALERT_WINDOW_MINUTES', 15)),
        'enrollment_rejection_warning' => max(1, (int) env('OPERATIONS_ENROLLMENT_REJECTION_WARNING', 5)),
        'enrollment_rejection_critical' => max(1, (int) env('OPERATIONS_ENROLLMENT_REJECTION_CRITICAL', 20)),
        'ai_api_alert_window_minutes' => max(1, (int) env('OPERATIONS_AI_API_ALERT_WINDOW_MINUTES', 60)),
        'ai_api_budget_warning_percent' => max(1, min(100, (int) env('OPERATIONS_AI_API_BUDGET_WARNING_PERCENT', 90))),
        'alert_cooldown_minutes' => max(1, (int) env('OPERATIONS_ALERT_COOLDOWN_MINUTES', 30)),
    ],
];
