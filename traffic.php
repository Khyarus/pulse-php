<?php

declare(strict_types=1);

use PulsePHP\Pulse;

require __DIR__ . '/vendor/autoload.php';

$iterationLimit = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--iterations=')) {
        $value = substr($argument, strlen('--iterations='));
        if (!ctype_digit($value) || (int) $value < 1) {
            fwrite(STDERR, "Usage: php traffic.php [--iterations=positive-number]\n");
            exit(2);
        }
        $iterationLimit = (int) $value;
    }
}

$routes = [
    ['api-vendas', 'GET', '/api/v1/orders'],
    ['api-vendas', 'GET', '/api/v1/products'],
    ['api-vendas', 'POST', '/api/v1/checkout'],
    ['auth-service', 'GET', '/api/v1/profile'],
    ['auth-service', 'POST', '/api/v1/login'],
    ['gateway-pagamento', 'POST', '/api/v1/payments'],
    ['gateway-pagamento', 'GET', '/api/v1/payments/{id}'],
];

$databasePath = __DIR__ . '/storage/database.sqlite';
$initialService = $routes[0][0];
$pulse = Pulse::init($databasePath, false, $initialService);
$useColors = function_exists('stream_isatty') && stream_isatty(STDOUT);
$colorize = static function (string $text, string $color) use ($useColors): string {
    return $useColors ? "\033[{$color}m{$text}\033[0m" : $text;
};
$iteration = 0;

fwrite(STDOUT, "PulsePHP traffic generator started. Press Ctrl+C to stop.\n");

while (true) {
    [$service, $method, $route] = $routes[array_rand($routes)];
    $durationMs = random_int(40, 1200);
    $statusCode = $route === '/api/v1/checkout' && random_int(1, 100) <= 13 ? 500 : 200;

    $pulse->setContext($service, $route, $method);
    usleep($durationMs * 1000);
    $pulse->recordRequest(
        $route,
        $method,
        $statusCode,
        (float) $durationMs,
        memory_get_usage(true),
        '127.0.0.1'
    );
    $pulse->recordMetric('simulator.request', 1.0, [
        'method' => $method,
        'status' => (string) $statusCode,
    ]);

    if ($statusCode >= 500) {
        $pulse->recordException(
            new RuntimeException('Simulated HTTP 500 for ' . $method . ' ' . $route),
            true
        );
    }

    $pulse->flush();
    $statusLabel = $statusCode >= 500
        ? $colorize($statusCode . ' ERROR', '31;1')
        : $colorize((string) $statusCode . ' OK', '32');
    $methodLabel = $method === 'GET'
        ? $colorize($method, '34;1')
        : $colorize($method, '33;1');
    $timestamp = date('H:i:s');

    printf(
        "[%s] %s %s - %dms (%s) [%s]\n",
        $timestamp,
        $methodLabel,
        $route,
        $durationMs,
        $statusLabel,
        $service
    );

    $iteration++;
    if ($iterationLimit !== null && $iteration >= $iterationLimit) {
        break;
    }

    sleep(1);
}
