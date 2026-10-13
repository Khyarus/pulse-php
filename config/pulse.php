<?php

declare(strict_types=1);

$csv = static fn (string $value): array => $value === ''
    ? []
    : array_values(array_filter(array_map('trim', explode(',', $value))));

return [
    'enabled' => env('PULSE_ENABLED', true),
    'database_path' => env('PULSE_DB_PATH', storage_path('pulse.sqlite')),
    'service_name' => env('PULSE_SERVICE_NAME', env('APP_NAME', 'default')),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    | Access is denied until the `viewPulse` Gate allows it. Basic Auth and the
    | IP whitelist are optional extra layers checked on top of the Gate.
    */
    'dashboard_path' => env('PULSE_PATH', 'pulse'),
    'dashboard_middleware' => ['web'],
    'dashboard_user' => env('PULSE_DASHBOARD_USER'),
    'dashboard_password' => env('PULSE_DASHBOARD_PASSWORD'),
    'dashboard_allowed_ips' => $csv((string) env('PULSE_DASHBOARD_IPS', '')),

    /*
    |--------------------------------------------------------------------------
    | Collection
    |--------------------------------------------------------------------------
    */
    'collect_queries' => env('PULSE_COLLECT_QUERIES', true),
    'collect_requests' => env('PULSE_COLLECT_REQUESTS', true),
    'collect_exceptions' => env('PULSE_COLLECT_EXCEPTIONS', true),
    'collect_outbound_requests' => env('PULSE_COLLECT_OUTBOUND', true),
    'collect_jobs' => env('PULSE_COLLECT_JOBS', true),
    'collect_logs' => env('PULSE_COLLECT_LOGS', true),

    // Lowest log level stored (debug, info, notice, warning, error, critical…).
    'log_level' => env('PULSE_LOG_LEVEL', 'warning'),

    // Fraction (0.0–1.0) of requests and jobs recorded. Exceptions are always kept.
    'sample_rate' => (float) env('PULSE_SAMPLE_RATE', 1.0),

    // Paths never recorded (`*` is a wildcard). The dashboard is always ignored.
    'ignore_paths' => $csv((string) env('PULSE_IGNORE_PATHS', '/up,/health,/_debugbar/*,/telescope/*,/horizon/*')),

    // Buffered records that force an early write (protects long-running workers).
    'max_buffer_size' => (int) env('PULSE_MAX_BUFFER', 1000),

    // Repetitions of the same query inside one request/job flagged as N+1.
    'n_plus_one_threshold' => (int) env('PULSE_N_PLUS_ONE_THRESHOLD', 5),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | Telemetry older than `retention_days` is deleted. Schedule `pulse:prune`
    | daily; the lottery is a fallback that prunes during normal writes.
    */
    'retention_days' => (int) env('PULSE_RETENTION_DAYS', 7),
    'prune_lottery' => [1, 200],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    | Evaluated by `php artisan pulse:check` (schedule it every minute).
    | Set a threshold to null to disable that rule.
    */
    'alerts' => [
        'enabled' => env('PULSE_ALERTS_ENABLED', false),
        'window_minutes' => (int) env('PULSE_ALERT_WINDOW', 5),
        'cooldown_minutes' => (int) env('PULSE_ALERT_COOLDOWN', 30),
        'min_requests' => 20,
        'error_rate_percent' => 5.0,
        'exceptions' => 10,
        'p95_ms' => 2000,
        'webhook_url' => env('PULSE_ALERT_WEBHOOK'),
        // 'json' (generic payload) or 'slack' (`{"text": ...}`; Discord accepts it on `<webhook>/slack`).
        'webhook_format' => env('PULSE_ALERT_WEBHOOK_FORMAT', 'json'),
        'mail_to' => $csv((string) env('PULSE_ALERT_MAIL_TO', '')),
    ],
];
