<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PulsePHP\Storage\QueryEngine;
use PulsePHP\Storage\SQLiteStorage;

final class RetentionAndMigrationTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulse-retention-');
        self::assertNotFalse($path);
        unlink($path);
        $this->dbPath = $path . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dbPath . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_prune_deletes_rows_older_than_the_retention_window(): void
    {
        $storage = new SQLiteStorage($this->dbPath);
        $storage->writeBatch([
            'requests' => [
                ['url' => '/old', 'method' => 'GET', 'status_code' => 200, 'duration_ms' => 1.0, 'memory_bytes' => 0, 'ip' => null],
                ['url' => '/new', 'method' => 'GET', 'status_code' => 200, 'duration_ms' => 1.0, 'memory_bytes' => 0, 'ip' => null],
            ],
            'queries' => [
                ['hash' => 'old-hash', 'normalized_sql' => 'SELECT old', 'duration_ms' => 1.0],
                ['hash' => 'new-hash', 'normalized_sql' => 'SELECT new', 'duration_ms' => 1.0],
            ],
        ]);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec("UPDATE pulse_requests SET created_at = datetime('now', '-10 days') WHERE url = '/old'");
        $pdo->exec("UPDATE pulse_queries SET created_at = datetime('now', '-10 days') WHERE catalog_id = (SELECT id FROM pulse_catalog WHERE hash = 'old-hash')");

        $deleted = $storage->prune(7, true);

        self::assertSame(1, $deleted['pulse_requests']);
        self::assertSame(1, $deleted['pulse_queries']);
        self::assertSame(1, $deleted['pulse_catalog'], 'Orphaned catalog entries are removed.');
        self::assertSame(['/new'], $pdo->query('SELECT url FROM pulse_requests')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['SELECT new'], $pdo->query('SELECT normalized_sql FROM pulse_catalog')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_prune_rejects_a_retention_below_one_day(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SQLiteStorage(':memory:'))->prune(0);
    }

    public function test_databases_from_older_versions_are_upgraded_in_place(): void
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec(
            'CREATE TABLE pulse_exceptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL, file TEXT NOT NULL,
                line INTEGER NOT NULL, trace TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'
        );
        $pdo->exec(
            'CREATE TABLE pulse_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL, method TEXT NOT NULL,
                status_code INTEGER NOT NULL, duration_ms REAL NOT NULL, memory_bytes INTEGER NOT NULL,
                ip TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'
        );
        $pdo->exec("INSERT INTO pulse_exceptions (message, file, line, trace) VALUES ('legacy', 'a.php', 3, '')");
        $pdo->exec("INSERT INTO pulse_requests (url, method, status_code, duration_ms, memory_bytes) VALUES ('/legacy', 'GET', 200, 4, 0)");
        unset($pdo);

        new SQLiteStorage($this->dbPath);
        $queries = new QueryEngine($this->dbPath);

        $groups = $queries->getExceptionGroups();
        self::assertCount(1, $groups);
        self::assertSame('legacy', $groups[0]['message']);
        self::assertSame('legacy:a.php:3', $groups[0]['fingerprint']);
        self::assertSame('', $queries->getRecentRequests(10, 'all')[0]['trace_id']);
    }
}
