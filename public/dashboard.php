<?php

declare(strict_types=1);

use PulsePHP\Dashboard\Dashboard;
use PulsePHP\Pulse;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dbPath = dirname(__DIR__) . '/storage/database.sqlite';
Pulse::init($dbPath);

(new Dashboard($dbPath))->render();