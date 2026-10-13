<?php

declare(strict_types=1);

namespace PulsePHP\Storage;

use InvalidArgumentException;
use PDO;
use PulsePHP\Contracts\StorageInterface;
use RuntimeException;
use Throwable;

final class SQLiteStorage implements StorageInterface
{
    private const TELEMETRY_TABLES = [
        'pulse_metrics',
        'pulse_spans',
        'pulse_exceptions',
        'pulse_requests',
        'pulse_queries',
        'pulse_outbound_requests',
        'pulse_logs',
        'pulse_deploys',
    ];

    public const EXCEPTION_STATUSES = ['open', 'resolved', 'ignored'];

    private PDO $pdo;
    private bool $inMemory;

    public function __construct(string $dbPath)
    {
        $this->inMemory = $dbPath === ':memory:';

        if (!$this->inMemory && !is_dir(dirname($dbPath))) {
            if (!mkdir(dirname($dbPath), 0o775, true) && !is_dir(dirname($dbPath))) {
                throw new RuntimeException('Unable to create the SQLite database directory.');
            }
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        // WAL improves read/write concurrency, which matters when the collector
        // writes batches while the dashboard reads. It is not supported by
        // in-memory databases, so we only enable it for on-disk files.
        if (!$this->inMemory) {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }

        $schemaPath = dirname(__DIR__, 2) . '/database/schema.sql';
        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new RuntimeException('Unable to read the PulsePHP database schema.');
        }

        $this->migrateColumns();
        $this->pdo->exec($schema);
        $this->migrateColumns();

        // Rows written before exception grouping existed get a stable
        // fingerprint so they still group by location.
        $this->pdo->exec(
            "UPDATE pulse_exceptions SET fingerprint = 'legacy:' || file || ':' || line WHERE fingerprint = ''"
        );
    }

    /** @param array<string, list<array<string, mixed>>> $buffer */
    public function writeBatch(array $buffer): void
    {
        if ($buffer === []) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $this->writeMetrics($buffer['metrics'] ?? []);
            $this->writeSpans($buffer['spans'] ?? []);
            $this->writeExceptions($buffer['exceptions'] ?? []);
            $this->writeRequests($buffer['requests'] ?? []);
            $this->writeQueries($buffer['queries'] ?? []);
            $this->writeOutboundRequests($buffer['outbound_requests'] ?? []);
            $this->writeLogs($buffer['logs'] ?? []);
            $this->writeDeploys($buffer['deploys'] ?? []);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Deletes telemetry older than the retention window.
     *
     * @return array<string, int> Deleted rows per table.
     */
    public function prune(int $retentionDays, bool $vacuum = false): array
    {
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('Retention must be at least one day.');
        }

        $modifier = sprintf('-%d days', $retentionDays);
        $deleted = [];
        $this->pdo->beginTransaction();

        try {
            foreach (self::TELEMETRY_TABLES as $table) {
                $statement = $this->pdo->prepare(
                    'DELETE FROM ' . $table . " WHERE created_at < datetime('now', :modifier)"
                );
                $statement->execute([':modifier' => $modifier]);
                $deleted[$table] = $statement->rowCount();
            }

            $deleted['pulse_catalog'] = (int) $this->pdo->exec(
                'DELETE FROM pulse_catalog WHERE id NOT IN (SELECT DISTINCT catalog_id FROM pulse_queries)'
            );
            $deleted['pulse_exception_states'] = (int) $this->pdo->exec(
                'DELETE FROM pulse_exception_states '
                . 'WHERE fingerprint NOT IN (SELECT DISTINCT fingerprint FROM pulse_exceptions)'
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        if ($vacuum && !$this->inMemory) {
            $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $this->pdo->exec('VACUUM');
        }

        return $deleted;
    }

    /** Returns when an alert rule last fired (UTC, `Y-m-d H:i:s`), or null. */
    public function lastAlertAt(string $rule): ?string
    {
        $statement = $this->pdo->prepare('SELECT last_triggered_at FROM pulse_alert_state WHERE rule = :rule');
        $statement->execute([':rule' => $rule]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function markAlert(string $rule): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_alert_state (rule, last_triggered_at) VALUES (:rule, CURRENT_TIMESTAMP) '
            . 'ON CONFLICT(rule) DO UPDATE SET last_triggered_at = excluded.last_triggered_at'
        );
        $statement->execute([':rule' => $rule]);
    }

    /**
     * Marks an exception group as open, resolved or ignored. A resolved group
     * that happens again is reported as regressed by the query engine.
     */
    public function setExceptionStatus(string $fingerprint, string $status): void
    {
        if (!in_array($status, self::EXCEPTION_STATUSES, true)) {
            throw new InvalidArgumentException('Unknown exception status: ' . $status);
        }

        if ($status === 'open') {
            $statement = $this->pdo->prepare('DELETE FROM pulse_exception_states WHERE fingerprint = :fingerprint');
            $statement->execute([':fingerprint' => $fingerprint]);

            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_exception_states (fingerprint, status, updated_at) '
            . 'VALUES (:fingerprint, :status, CURRENT_TIMESTAMP) '
            . 'ON CONFLICT(fingerprint) DO UPDATE SET status = excluded.status, updated_at = excluded.updated_at'
        );
        $statement->execute([':fingerprint' => $fingerprint, ':status' => $status]);
    }

    private function writeMetrics(array $metrics): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_metrics (name, value, tags, service, route) '
            . 'VALUES (:name, :value, :tags, :service, :route)'
        );

        foreach ($metrics as $metric) {
            $statement->execute([
                ':name' => $metric['name'],
                ':value' => $metric['value'],
                ':tags' => json_encode($metric['tags'] ?? [], JSON_THROW_ON_ERROR),
                ':service' => $metric['service'] ?? 'default',
                ':route' => $metric['route'] ?? '/',
            ]);
        }
    }

    private function writeSpans(array $spans): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_spans (name, duration_ms, memory_bytes, service, route, trace_id, offset_ms) '
            . 'VALUES (:name, :duration_ms, :memory_bytes, :service, :route, :trace_id, :offset_ms)'
        );

        foreach ($spans as $span) {
            $statement->execute([
                ':name' => $span['name'],
                ':duration_ms' => $span['duration_ms'],
                ':memory_bytes' => $span['memory_bytes'],
                ':service' => $span['service'] ?? 'default',
                ':route' => $span['route'] ?? '/',
                ':trace_id' => $span['trace_id'] ?? '',
                ':offset_ms' => $span['offset_ms'] ?? null,
            ]);
        }
    }

    private function writeExceptions(array $exceptions): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_exceptions '
            . '(class, fingerprint, message, file, line, trace, method, unhandled, service, route, trace_id) '
            . 'VALUES (:class, :fingerprint, :message, :file, :line, :trace, :method, :unhandled, '
            . ':service, :route, :trace_id)'
        );

        foreach ($exceptions as $exception) {
            $class = (string) ($exception['class'] ?? '');
            $statement->execute([
                ':class' => $class,
                ':fingerprint' => $exception['fingerprint']
                    ?? sha1($class . '|' . $exception['file'] . '|' . $exception['line']),
                ':message' => $exception['message'],
                ':file' => $exception['file'],
                ':line' => $exception['line'],
                ':trace' => $exception['trace'],
                ':method' => $exception['method'] ?? '',
                ':unhandled' => (int) ($exception['unhandled'] ?? false),
                ':service' => $exception['service'] ?? 'default',
                ':route' => $exception['route'] ?? '/',
                ':trace_id' => $exception['trace_id'] ?? '',
            ]);
        }
    }

    private function writeRequests(array $requests): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_requests '
            . '(url, method, status_code, duration_ms, memory_bytes, ip, service, route, trace_id) '
            . 'VALUES (:url, :method, :status_code, :duration_ms, :memory_bytes, :ip, :service, :route, :trace_id)'
        );

        foreach ($requests as $request) {
            $statement->execute([
                ':url' => $request['url'],
                ':method' => $request['method'],
                ':status_code' => $request['status_code'],
                ':duration_ms' => $request['duration_ms'],
                ':memory_bytes' => $request['memory_bytes'],
                ':ip' => $request['ip'],
                ':service' => $request['service'] ?? 'default',
                ':route' => $request['route'] ?? '/',
                ':trace_id' => $request['trace_id'] ?? '',
            ]);
        }
    }

    private function writeOutboundRequests(array $requests): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_outbound_requests '
            . '(service, route, method, status_code, url, duration_ms, trace_id, offset_ms) '
            . 'VALUES (:service, :route, :method, :status_code, :url, :duration_ms, :trace_id, :offset_ms)'
        );

        foreach ($requests as $request) {
            $statement->execute([
                ':service' => $request['service'] ?? 'default',
                ':route' => $request['route'] ?? '/',
                ':method' => $request['method'],
                ':status_code' => $request['status_code'] ?? 0,
                ':url' => $request['url'],
                ':duration_ms' => $request['duration_ms'],
                ':trace_id' => $request['trace_id'] ?? '',
                ':offset_ms' => $request['offset_ms'] ?? null,
            ]);
        }
    }

    private function writeLogs(array $logs): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_logs (level, message, context, service, route, trace_id) '
            . 'VALUES (:level, :message, :context, :service, :route, :trace_id)'
        );

        foreach ($logs as $log) {
            $statement->execute([
                ':level' => $log['level'],
                ':message' => $log['message'],
                ':context' => json_encode(
                    $log['context'] ?? [],
                    JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) ?: '{}',
                ':service' => $log['service'] ?? 'default',
                ':route' => $log['route'] ?? '/',
                ':trace_id' => $log['trace_id'] ?? '',
            ]);
        }
    }

    private function writeDeploys(array $deploys): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_deploys (version, service) VALUES (:version, :service)'
        );

        foreach ($deploys as $deploy) {
            $statement->execute([
                ':version' => $deploy['version'],
                ':service' => $deploy['service'] ?? 'default',
            ]);
        }
    }

    private function writeQueries(array $queries): void
    {
        $catalogStatement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO pulse_catalog (hash, normalized_sql) '
            . 'VALUES (:hash, :normalized_sql)'
        );
        $lookupStatement = $this->pdo->prepare(
            'SELECT id FROM pulse_catalog WHERE hash = :hash'
        );
        $queryStatement = $this->pdo->prepare(
            'INSERT INTO pulse_queries (catalog_id, duration_ms, service, route, trace_id, offset_ms) '
            . 'VALUES (:catalog_id, :duration_ms, :service, :route, :trace_id, :offset_ms)'
        );

        foreach ($queries as $query) {
            $catalogStatement->execute([
                ':hash' => $query['hash'],
                ':normalized_sql' => $query['normalized_sql'],
            ]);
            $lookupStatement->execute([':hash' => $query['hash']]);
            $catalogId = $lookupStatement->fetchColumn();

            if ($catalogId === false) {
                throw new RuntimeException('Unable to resolve a SQL catalog entry.');
            }

            $queryStatement->execute([
                ':catalog_id' => (int) $catalogId,
                ':duration_ms' => $query['duration_ms'],
                ':service' => $query['service'] ?? 'default',
                ':route' => $query['route'] ?? '/',
                ':trace_id' => $query['trace_id'] ?? '',
                ':offset_ms' => $query['offset_ms'] ?? null,
            ]);
        }
    }

    /**
     * Adds columns introduced after a table was first created, so databases
     * written by older versions keep working without losing records.
     */
    private function migrateColumns(): void
    {
        $context = [
            'service' => "TEXT NOT NULL DEFAULT 'default'",
            'route' => "TEXT NOT NULL DEFAULT '/'",
        ];
        $traced = $context + ['trace_id' => "TEXT NOT NULL DEFAULT ''"];
        $timed = $traced + ['offset_ms' => 'REAL'];
        $tables = [
            'pulse_metrics' => $context,
            'pulse_spans' => $timed,
            'pulse_exceptions' => $traced + [
                'method' => "TEXT NOT NULL DEFAULT ''",
                'unhandled' => 'INTEGER NOT NULL DEFAULT 0',
                'class' => "TEXT NOT NULL DEFAULT ''",
                'fingerprint' => "TEXT NOT NULL DEFAULT ''",
            ],
            'pulse_requests' => $traced,
            'pulse_queries' => $timed,
            'pulse_outbound_requests' => $timed,
        ];

        foreach ($tables as $table => $definitions) {
            $columns = $this->pdo->query('PRAGMA table_info(' . $table . ')')
                ->fetchAll(PDO::FETCH_COLUMN, 1);
            if ($columns === []) {
                continue;
            }

            foreach ($definitions as $column => $definition) {
                if (!in_array($column, $columns, true)) {
                    $this->pdo->exec(
                        sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition)
                    );
                }
            }
        }
    }
}
