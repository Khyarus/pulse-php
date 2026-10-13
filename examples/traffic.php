<?php

declare(strict_types=1);

use PulsePHP\Pulse;

require __DIR__ . '/../vendor/autoload.php';

$iterationLimit = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, "--iterations=")) {
        $value = substr($argument, strlen("--iterations="));
        if (!ctype_digit($value) || (int) $value < 1) {
            fwrite(STDERR, "Usage: php traffic.php [--iterations=positive-number]\n");
            exit(2);
        }
        $iterationLimit = (int) $value;
    }
}

$routes = [
    ["api-vendas", "GET", "/api/v1/orders"],
    ["api-vendas", "GET", "/api/v1/products"],
    ["api-vendas", "POST", "/api/v1/checkout"],
    ["auth-service", "GET", "/api/v1/profile"],
    ["auth-service", "POST", "/api/v1/login"],
    ["gateway-pagamento", "POST", "/api/v1/payments"],
    ["gateway-pagamento", "GET", "/api/v1/payments/{id}"],
];

$queryTemplates = [
    "SELECT * FROM orders WHERE customer_id = :id ORDER BY created_at DESC",
    "SELECT * FROM products WHERE category_id = :id AND active = 1 LIMIT 50",
    "SELECT COUNT(*) FROM payments WHERE status = :status",
    "SELECT * FROM users WHERE email = :email LIMIT 1",
    "INSERT INTO orders (customer_id, total, status) VALUES (:customer_id, :total, :status)",
    "UPDATE inventory SET quantity = quantity - :qty WHERE product_id = :id",
    "SELECT o.id, o.total, u.name FROM orders o JOIN users u ON u.id = o.customer_id WHERE o.status = :status",
];

$spanTemplates = [
    "db.query",
    "cache.get",
    "http.client",
    "view.render",
    "queue.dispatch",
    "payment.authorize",
];

$outboundTemplates = [
    "https://api.stripe.com/v1/charges",
    "https://api.correios.com.br/v1/rastreamento",
    "https://mailer.internal/send",
    "https://viacep.com.br/ws/01001000/json",
    "https://api.mercadopago.com/v1/payments",
];

$exceptionTemplates = [
    "RuntimeException" => "Simulated failure while processing %s",
    "PDOException" => "SQLSTATE[HY000] [2002] Connection timed out (query: %s)",
    "ConnectException" => "cURL error 28: Operation timed out for %s",
];

$databasePath = __DIR__ . "/../storage/database.sqlite";
$initialService = $routes[0][0];
$pulse = Pulse::init($databasePath, false, $initialService);
$useColors = function_exists("stream_isatty") && stream_isatty(STDOUT);
$colorize = static function (string $text, string $color) use ($useColors): string {
    return $useColors ? "\033[{$color}m{$text}\033[0m" : $text;
};
$iteration = 0;

$pulse->deploy("demo-" . gmdate("Hi"));
fwrite(STDOUT, "PulsePHP traffic generator started. Press Ctrl+C to stop.\n");

while (true) {
    [$service, $method, $route] = $routes[array_rand($routes)];
    // Each simulated request is its own trace, like a real web request.
    $pulse->beginTrace($route, $method, $route, $service);
    $startedAt = hrtime(true);
    // Simulated work sleeps for a quarter of its nominal time to keep the demo fast.
    $work = static fn (int $nominalMs) => usleep(max(1, intdiv($nominalMs * 1000, 4)));
    $timed = static function (callable $operation): float {
        $start = hrtime(true);
        $operation();

        return (hrtime(true) - $start) / 1_000_000;
    };

    // Simulated in-app spans (populates "Slow spans").
    $spanCount = random_int(1, 3);
    for ($i = 0; $i < $spanCount; $i++) {
        $span = $spanTemplates[array_rand($spanTemplates)];
        $pulse->startTimer($span);
        $work(random_int(10, 100) <= 20 ? random_int(200, 900) : random_int(5, 120));
        $pulse->endTimer($span);
    }

    // Simulated database queries; one route has an N+1 loop.
    $queryCount = $route === "/api/v1/orders" ? random_int(6, 12) : random_int(2, 5);
    for ($i = 0; $i < $queryCount; $i++) {
        $template = $route === "/api/v1/orders" && $i > 0
            ? "SELECT * FROM order_items WHERE order_id = " . random_int(1, 9999)
            : $queryTemplates[array_rand($queryTemplates)];
        $nominal = random_int(1, 100) <= 15 ? random_int(250, 1200) : random_int(2, 80);
        $pulse->recordQuery($template, $timed(static fn () => $work($nominal)));
    }

    // Simulated outbound API calls (populates "Outbound HTTP").
    $outboundCount = random_int(0, 2);
    for ($i = 0; $i < $outboundCount; $i++) {
        $url = $outboundTemplates[array_rand($outboundTemplates)];
        $outboundMethod = random_int(1, 100) <= 60 ? "GET" : "POST";
        $outboundStatus = random_int(1, 100) <= 10 ? random_int(500, 503) : 200;
        $nominal = random_int(1, 100) <= 25 ? random_int(250, 1500) : random_int(30, 250);
        $pulse->recordOutboundRequest($url, $outboundMethod, $outboundStatus, $timed(static fn () => $work($nominal)));
    }

    // Simulated failures (populates "Errors" and "Logs").
    $statusCode = $route === "/api/v1/checkout" && random_int(1, 100) <= 13 ? 500 : 200;
    $hasException = false;
    $exceptionClass = "";
    if ($statusCode >= 500 || random_int(1, 100) <= 6) {
        $exceptionClass = array_rand($exceptionTemplates);
        $exceptionMessage = sprintf($exceptionTemplates[$exceptionClass], $method . " " . $route);
        $pulse->recordException(new RuntimeException($exceptionMessage), $statusCode >= 500);
        $pulse->recordLog("error", "Request failed: " . $exceptionMessage, ["route" => $route]);
        $hasException = true;
    }

    $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
    if ($durationMs > 400) {
        $pulse->recordLog("warning", sprintf("Slow request: %s %s took %d ms", $method, $route, $durationMs), [
            "threshold_ms" => 400,
        ]);
    }
    $pulse->recordRequest($route, $method, $statusCode, (float) $durationMs, memory_get_usage(true), "127.0.0.1");
    $pulse->recordMetric("simulator.request", 1.0, [
        "method" => $method,
        "status" => (string) $statusCode,
    ]);

    $pulse->flush();
    $statusLabel = $statusCode >= 500
        ? $colorize($statusCode . " ERROR", "31;1")
        : $colorize((string) $statusCode . " OK", "32");
    $methodLabel = $method === "GET"
        ? $colorize($method, "34;1")
        : $colorize($method, "33;1");
    $timestamp = date("H:i:s");

    $exceptionLabel = $hasException
        ? ' ' . $colorize('EXCEPTION', '35;1') . '(' . $colorize($exceptionClass, '35') . ')'
        : '';

    printf(
        "[%s] %s %s - %dms (%s) [%s] queries=%d spans=%d outbound=%d%s\n",
        $timestamp,
        $methodLabel,
        $route,
        $durationMs,
        $statusLabel,
        $service,
        $queryCount,
        $spanCount,
        $outboundCount,
        $exceptionLabel
    );

    $iteration++;
    if ($iterationLimit !== null && $iteration >= $iterationLimit) {
        break;
    }

    sleep(1);
}
