<?php

declare(strict_types=1);

use PulsePHP\Pulse;

if (!function_exists('pulse')) {
    function pulse(string $event): void
    {
        Pulse::getInstance()->recordEvent($event);
    }
}

if (!function_exists('pulse_metric')) {
    function pulse_metric(string $name, float $value, array $tags = []): void
    {
        Pulse::getInstance()->recordMetric($name, $value, $tags);
    }
}

if (!function_exists('pulse_start')) {
    function pulse_start(string $key): void
    {
        Pulse::getInstance()->startTimer($key);
    }
}

if (!function_exists('pulse_end')) {
    function pulse_end(string $key): void
    {
        Pulse::getInstance()->endTimer($key);
    }
}

if (!function_exists('pulse_pulse')) {
    function pulse_pulse(): void
    {
        Pulse::getInstance()->recordMetric('pulse.health.memory_bytes', (float) memory_get_usage(true));
        Pulse::getInstance()->recordMetric('pulse.health.php_version', (float) PHP_VERSION_ID);
    }
}