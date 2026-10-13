<?php

declare(strict_types=1);

use PulsePHP\Dashboard\Dashboard;

if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The dashboard does not record its own page views or live-refresh polling,
// so it never pollutes the metrics it displays.
$dbPath = getenv('PULSE_DB_PATH') ?: dirname(__DIR__) . '/storage/database.sqlite';

$dashboard = (new Dashboard($dbPath))->withDataEndpoint('dashboard-data.php');
$dashboardUser = getenv('PULSE_DASHBOARD_USER');
$dashboardPassword = getenv('PULSE_DASHBOARD_PASSWORD');

if ($dashboardUser !== false || $dashboardPassword !== false) {
    if ($dashboardUser === false || $dashboardPassword === false
        || $dashboardUser === '' || $dashboardPassword === '') {
        $dashboard->authorize(static fn (): bool => false);
    } else {
        $dashboard->authWithBasic($dashboardUser, $dashboardPassword);
    }
}

$dashboard->authWithIp(['127.0.0.1', '::1']);

return $dashboard;
