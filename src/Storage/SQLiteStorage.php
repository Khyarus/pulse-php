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

        $this->pdo->exec($schema);
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
            'INSERT INTO pulse_metrics (name, value, tags) VALUES (:name, :value, :tags)'
        );

        foreach ($metrics as $metric) {
            $statement->execute([
                ':name' => $metric['name'],
                ':value' => $metric['value'],
                ':tags' => json_encode($metric['tags'] ?? [], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private function writeSpans(array $spans): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_spans (name, duration_ms, memory_bytes) '
            . 'VALUES (:name, :duration_ms, :memory_bytes)'
        );

        foreach ($spans as $span) {
            $statement->execute([
                ':name' => $span['name'],
                ':duration_ms' => $span['duration_ms'],
                ':memory_bytes' => $span['memory_bytes'],
            ]);
        }
    }

    private function writeExceptions(array $exceptions): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_exceptions (message, file, line, trace) '
            . 'VALUES (:message, :file, :line, :trace)'
        );

        foreach ($exceptions as $exception) {
            $statement->execute([
                ':message' => $exception['message'],
                ':file' => $exception['file'],
                ':line' => $exception['line'],
                ':trace' => $exception['trace'],
            ]);
        }
    }

    private function writeRequests(array $requests): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pulse_requests '
            . '(url, method, status_code, duration_ms, memory_bytes, ip) '
            . 'VALUES (:url, :method, :status_code, :duration_ms, :memory_bytes, :ip)'
        );

        foreach ($requests as $request) {
            $statement->execute([
                ':url' => $request['url'],
                ':method' => $request['method'],
                ':status_code' => $request['status_code'],
                ':duration_ms' => $request['duration_ms'],
                ':memory_bytes' => $request['memory_bytes'],
                ':ip' => $request['ip'],
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
            'INSERT INTO pulse_queries (catalog_id, duration_ms) '
            . 'VALUES (:catalog_id, :duration_ms)'
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
            ]);
        }
    }
}