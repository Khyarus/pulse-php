<?php

declare(strict_types=1);

namespace PulsePHP\Storage;

use PDO;
use PulsePHP\Contracts\StorageInterface;
use RuntimeException;
use Throwable;

final class SQLiteStorage implements StorageInterface
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        if ($dbPath !== ':memory:' && !is_dir(dirname($dbPath))) {
            if (!mkdir(dirname($dbPath), 0775, true) && !is_dir(dirname($dbPath))) {
                throw new RuntimeException('Unable to create the SQLite database directory.');
            }
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $schemaPath = dirname(__DIR__, 2) . '/database/schema.sql';
        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new RuntimeException('Unable to read the PulsePHP database schema.');
        }

        $this->migrateContextColumns();
        $this->pdo->exec($schema);
        $this->migrateContextColumns();
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
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
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
            'INSERT INTO pulse_spans (name, duration_ms, memory_bytes, service, route) '
            . 'VALUES (:name, :duration_ms, :memory_bytes, :service, :route)'
        );

        foreach ($spans as $span) {
            $statement->execute([
                ':name' => $span['name'],
                ':duration_ms' => $span['duration_ms'],
                ':memory_bytes' => $span['memory_bytes'],
                ':service' => $span['service'] ?? 'default',
                ':route' => $span['route'] ?? '/',
            ]);
        }
    }

    private function writeExceptions(array $exceptions): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_exceptions (message, file, line, trace, method, unhandled, service, route) '
            . 'VALUES (:message, :file, :line, :trace, :method, :unhandled, :service, :route)'
        );

        foreach ($exceptions as $exception) {
            $statement->execute([
                ':message' => $exception['message'],
                ':file' => $exception['file'],
                ':line' => $exception['line'],
                ':trace' => $exception['trace'],
                ':method' => $exception['method'] ?? '',
                ':unhandled' => (int) ($exception['unhandled'] ?? false),
                ':service' => $exception['service'] ?? 'default',
                ':route' => $exception['route'] ?? '/',
            ]);
        }
    }

    private function writeRequests(array $requests): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_requests '
            . '(url, method, status_code, duration_ms, memory_bytes, ip, service, route) '
            . 'VALUES (:url, :method, :status_code, :duration_ms, :memory_bytes, :ip, :service, :route)'
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
            ]);
        }
    }

    private function writeOutboundRequests(array $requests): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_outbound_requests '
            . '(service, route, method, status_code, url, duration_ms) '
            . 'VALUES (:service, :route, :method, :status_code, :url, :duration_ms)'
        );

        foreach ($requests as $request) {
            $statement->execute([
                ':service' => $request['service'] ?? 'default',
                ':route' => $request['route'] ?? '/',
                ':method' => $request['method'],
                ':status_code' => $request['status_code'] ?? 0,
                ':url' => $request['url'],
                ':duration_ms' => $request['duration_ms'],
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
            'INSERT INTO pulse_queries (catalog_id, duration_ms, service, route) '
            . 'VALUES (:catalog_id, :duration_ms, :service, :route)'
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
            ]);
        }
    }

    private function migrateContextColumns(): void
    {
        $tables = [
            'pulse_metrics',
            'pulse_spans',
            'pulse_exceptions',
            'pulse_requests',
            'pulse_queries',
        ];
        $definitions = [
            'service' => "TEXT NOT NULL DEFAULT 'default'",
            'route' => "TEXT NOT NULL DEFAULT '/'",
        ];

        foreach ($tables as $table) {
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

            if ($table === 'pulse_exceptions') {
                foreach ([
                    'method' => "TEXT NOT NULL DEFAULT ''",
                    'unhandled' => 'INTEGER NOT NULL DEFAULT 0',
                ] as $column => $definition) {
                    if (!in_array($column, $columns, true)) {
                        $this->pdo->exec(
                            sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition)
                        );
                    }
                }
            }
        }
    }
}