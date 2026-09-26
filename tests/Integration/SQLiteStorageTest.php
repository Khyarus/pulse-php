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
        ]);

        foreach (['metrics', 'spans', 'exceptions', 'requests', 'catalog', 'queries'] as $table) {
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM pulse_{$table}")->fetchColumn());
        }
        self::assertSame(
            ['env' => 'test'],
            json_decode((string) $pdo->query('SELECT tags FROM pulse_metrics')->fetchColumn(), true)
        );
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM pulse_queries WHERE catalog_id = 1')->fetchColumn());
    }
}