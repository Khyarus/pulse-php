<?php

declare(strict_types=1);

use PulsePHP\Dashboard\Dashboard;
use PulsePHP\Pulse;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dbPath = dirname(__DIR__) . '/storage/database.sqlite';
Pulse::init($dbPath);

$dashboard = new Dashboard($dbPath);
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
$dashboard->render();