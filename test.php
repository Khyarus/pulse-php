<?php

declare(strict_types=1);

use PulsePHP\Pulse;
use PulsePHP\Storage\CatalogEngine;

require_once __DIR__ . '/src/Support/helpers.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'PulsePHP\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "The pdo_sqlite extension is required to run this test.\n");
    exit(1);
}

$dbPath = __DIR__ . '/storage/database.sqlite';
$runId = bin2hex(random_bytes(6));
$_SERVER['REQUEST_URI'] = '/health-check?source=test&run=' . $runId;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
http_response_code(200);

try {
    $pulse = Pulse::init($dbPath);
    $pdo = new PDO('sqlite:' . $dbPath);
    $normalizedSql = CatalogEngine::normalizeSql(
        "SELECT * FROM users WHERE id = 42 AND email = 'person@example.test'"
    );
    $sqlHash = CatalogEngine::hashSql($normalizedSql);
    $queryCountBefore = (int) $pdo->query(
        'SELECT COUNT(*) FROM pulse_queries '
        . 'WHERE catalog_id = (SELECT id FROM pulse_catalog WHERE hash = ' . $pdo->quote($sqlHash) . ')'
    )->fetchColumn();

    pulse('test.started.' . $runId);
    pulse_metric('test.load.' . $runId, 0.75, ['suite' => 'smoke']);
    pulse_start('test.operation.' . $runId);
    pulse_end('test.operation.' . $runId);
    $pulse->recordQuery("SELECT * FROM users WHERE id = 42 AND email = 'person@example.test'", 1.25);
    $pulse->recordQuery("SELECT * FROM users WHERE id = 7 AND email = 'another@example.test'", 0.9);

    try {
        throw new RuntimeException('Simulated test exception ' . $runId);
    } catch (RuntimeException $exception) {
        $pulse->recordException($exception);
    }

    if (CatalogEngine::normalizeSql("SELECT * FROM users WHERE id = 42")
        !== CatalogEngine::normalizeSql("SELECT * FROM users WHERE id = 7")) {
        throw new RuntimeException('SQL normalization did not replace numeric values.');
    }
    if (CatalogEngine::normalizeSql("SELECT * FROM users WHERE email = 'first@example.test'")
        !== CatalogEngine::normalizeSql("SELECT * FROM users WHERE email = 'second@example.test'")) {
        throw new RuntimeException('SQL normalization did not replace quoted string values.');
    }

    $pulse->flush();

    $checks = [
        [
            'SELECT COUNT(*) FROM pulse_metrics WHERE name IN (?, ?)',
            ['test.started.' . $runId, 'test.load.' . $runId],
            2,
            'test metrics',
        ],
        [
            'SELECT COUNT(*) FROM pulse_spans WHERE name = ?',
            ['test.operation.' . $runId],
            1,
            'test span',
        ],
        [
            'SELECT COUNT(*) FROM pulse_exceptions WHERE message = ?',
            ['Simulated test exception ' . $runId],
            1,
            'test exception',
        ],
        [
            'SELECT COUNT(*) FROM pulse_requests WHERE url = ?',
            ['/health-check?source=test&run=' . $runId],
            1,
            'test request',
        ],
        [
            'SELECT COUNT(*) FROM pulse_catalog WHERE hash = ?',
            [$sqlHash],
            1,
            'normalized SQL catalog entry',
        ],
        [
            'SELECT COUNT(*) FROM pulse_queries '
            . 'WHERE catalog_id = (SELECT id FROM pulse_catalog WHERE hash = ?)',
            [$sqlHash],
            $queryCountBefore + 2,
            'query executions',
        ],
    ];

    foreach ($checks as [$sql, $parameters, $expectedCount, $description]) {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $actualCount = (int) $statement->fetchColumn();
        if ($actualCount !== $expectedCount) {
            throw new RuntimeException(
                sprintf('%s expected %d row(s), found %d.', $description, $expectedCount, $actualCount)
            );
        }
    }

    $requestStatement = $pdo->prepare(
        'SELECT url, method, status_code, ip FROM pulse_requests WHERE url = ?'
    );
    $requestStatement->execute(['/health-check?source=test&run=' . $runId]);
    $request = $requestStatement->fetch(PDO::FETCH_ASSOC);
    if ($request === false
        || $request['url'] !== '/health-check?source=test&run=' . $runId
        || $request['method'] !== 'GET'
        || (int) $request['status_code'] !== 200
        || $request['ip'] !== '127.0.0.1') {
        throw new RuntimeException('The simulated HTTP request was not recorded correctly.');
    }

    echo "PulsePHP smoke test passed.\n";
} finally {
    if (isset($pdo)) {
        $pdo = null;
    }
}