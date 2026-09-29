<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PulsePHP\Storage\SQLiteStorage;
use ReflectionProperty;

final class SQLiteStorageTest extends TestCase
{
    public function test_it_creates_schema_and_writes_a_batch(): void
    {
        $storage = new SQLiteStorage(':memory:');
        $pdo = (new ReflectionProperty(SQLiteStorage::class, 'pdo'))->getValue($storage);
        self::assertInstanceOf(PDO::class, $pdo);

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'pulse_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertEqualsCanonicalizing([
            'pulse_metrics',
            'pulse_spans',
            'pulse_exceptions',
            'pulse_requests',
            'pulse_catalog',
            'pulse_queries',
            'pulse_outbound_requests',
        ], $tables);

        $storage->writeBatch([
            'metrics' => [['name' => 'batch.metric', 'value' => 2.5, 'tags' => ['env' => 'test']]],
            'spans' => [['name' => 'batch.span', 'duration_ms' => 4.2, 'memory_bytes' => 128]],
            'exceptions' => [[
                'message' => 'batch exception',
                'file' => 'test.php',
                'line' => 12,
                'trace' => 'trace',
            ]],
            'requests' => [[
                'url' => '/health',
                'method' => 'GET',
                'status_code' => 200,
                'duration_ms' => 1.2,
                'memory_bytes' => 256,
                'ip' => '127.0.0.1',
            ]],
            'queries' => [[
                'hash' => md5('SELECT 1'),
                'normalized_sql' => 'SELECT 1',
                'duration_ms' => 0.8,
            ]],
            'outbound_requests' => [[
                'service' => 'billing-api',
                'route' => 'checkout',
                'method' => 'GET',
                'status_code' => 200,
                'url' => 'https://example.test/health',
                'duration_ms' => 8.4,
            ]],
        ]);

        foreach ([
            'metrics', 'spans', 'exceptions', 'requests', 'catalog', 'queries', 'outbound_requests',
        ] as $table) {
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pulse_{$table}")->fetchColumn());
        }
        self::assertSame(
            ['env' => 'test'],
            json_decode((string) $pdo->query('SELECT tags FROM pulse_metrics')->fetchColumn(), true)
        );
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM pulse_queries WHERE catalog_id = 1')->fetchColumn());
        $outbound = $pdo->query(
            'SELECT service, route, method, status_code, url FROM pulse_outbound_requests'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('billing-api', $outbound['service']);
        self::assertSame('checkout', $outbound['route']);
        self::assertSame('GET', $outbound['method']);
        self::assertSame(200, (int) $outbound['status_code']);
    }

    public function test_it_configures_concurrency_pragmas_on_disk_databases(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'pulse-pragma-');
        self::assertNotFalse($dbPath);

        try {
            $storage = new SQLiteStorage($dbPath);
            $pdo = (new ReflectionProperty(SQLiteStorage::class, 'pdo'))->getValue($storage);
            self::assertInstanceOf(PDO::class, $pdo);

            self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
            self::assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        } finally {
            unset($pdo, $storage);
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $artifact) {
                if (is_file($artifact)) {
                    unlink($artifact);
                }
            }
        }
    }

    public function test_it_does_not_enable_wal_for_in_memory_databases(): void
    {
        $storage = new SQLiteStorage(':memory:');
        $pdo = (new ReflectionProperty(SQLiteStorage::class, 'pdo'))->getValue($storage);
        self::assertInstanceOf(PDO::class, $pdo);

        // busy_timeout still applies, but journal_mode stays default (memory).
        self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        self::assertNotSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
    }

    public function test_it_migrates_a_v1_database_without_losing_rows(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'pulse-v1-');
        self::assertNotFalse($dbPath);

        try {
            $legacyPdo = new PDO('sqlite:' . $dbPath);
            $legacyPdo->exec(
                'CREATE TABLE pulse_requests ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, method TEXT NOT NULL, '
                . 'status_code INTEGER NOT NULL, duration_ms REAL NOT NULL, memory_bytes INTEGER NOT NULL, '
                . 'ip TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'
            );
            $legacyPdo->exec(
                'INSERT INTO pulse_requests (url, method, status_code, duration_ms, memory_bytes, ip) '
                . "VALUES ('/legacy', 'GET', 200, 1.5, 128, '127.0.0.1')"
            );
            $legacyPdo->exec(
                'CREATE TABLE pulse_exceptions ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL, file TEXT NOT NULL, '
                . 'line INTEGER NOT NULL, trace TEXT NOT NULL, '
                . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'
            );
            $legacyPdo->exec(
                'INSERT INTO pulse_exceptions (message, file, line, trace) '
                . "VALUES ('legacy exception', 'legacy.php', 5, 'trace')"
            );
            unset($legacyPdo);

            new SQLiteStorage($dbPath);

            $migratedPdo = new PDO('sqlite:' . $dbPath);
            $request = $migratedPdo->query(
                'SELECT url, service, route FROM pulse_requests WHERE id = 1'
            )->fetch(PDO::FETCH_ASSOC);
            self::assertSame('/legacy', $request['url']);
            self::assertSame('default', $request['service']);
            self::assertSame('/', $request['route']);
            $exception = $migratedPdo->query(
                'SELECT method, unhandled FROM pulse_exceptions WHERE id = 1'
            )->fetch(PDO::FETCH_ASSOC);
            self::assertSame('', $exception['method']);
            self::assertSame(0, (int) $exception['unhandled']);
            unset($migratedPdo);
        } finally {
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }
}
