<?php

declare(strict_types=1);

$dashboardIps = (string) env('PULSE_DASHBOARD_IPS', '');

return [
    'enabled' => env('PULSE_ENABLED', true),
    'database_path' => storage_path('pulse.sqlite'),
    'service_name' => env('PULSE_SERVICE_NAME', env('APP_NAME', 'default')),
    'dashboard_path' => 'pulse',
    'dashboard_middleware' => ['web'],
    'dashboard_user' => env('PULSE_DASHBOARD_USER'),
    'dashboard_password' => env('PULSE_DASHBOARD_PASSWORD'),
    'dashboard_allowed_ips' => $dashboardIps === ''
        ? []
        : array_values(array_filter(array_map('trim', explode(',', $dashboardIps)))),
    'collect_queries' => true,
    'collect_requests' => true,
    'collect_exceptions' => true,
    'collect_outbound_requests' => true,
];