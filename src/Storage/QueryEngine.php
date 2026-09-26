<?php

declare(strict_types=1);

namespace PulsePHP\Storage;

use PDO;
use RuntimeException;

final class QueryEngine
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        if (!is_file($dbPath)) {
            throw new RuntimeException(sprintf('PulsePHP database not found: %s', $dbPath));
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA query_only = ON');
    }

    /** @return array{requests_total: int, avg_response_ms: float, peak_memory_bytes: int, exceptions_today: int} */
    public function getSummaryStats(): array
    {
        $statement = $this->pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM pulse_requests) AS requests_total,
                (SELECT COALESCE(AVG(duration_ms), 0) FROM pulse_requests) AS avg_response_ms,
                (SELECT COALESCE(MAX(memory_bytes), 0) FROM pulse_requests) AS peak_memory_bytes,
                (SELECT COUNT(*) FROM pulse_exceptions WHERE created_at >= date('now')) AS exceptions_today"
        );
        $stats = $statement->fetch();

        return [
            'requests_total' => (int) $stats['requests_total'],
            'avg_response_ms' => (float) $stats['avg_response_ms'],
            'peak_memory_bytes' => (int) $stats['peak_memory_bytes'],
            'exceptions_today' => (int) $stats['exceptions_today'],
        ];
    }

    /** @return list<array{normalized_sql: string, executions: int, avg_duration_ms: float, max_duration_ms: float}> */
    public function getSlowestQueries(int $limit = 10): array
    {
        $statement = $this->pdo->prepare(
            'SELECT catalog.normalized_sql,
                    COUNT(queries.id) AS executions,
                    AVG(queries.duration_ms) AS avg_duration_ms,
                    MAX(queries.duration_ms) AS max_duration_ms
             FROM pulse_queries AS queries
             INNER JOIN pulse_catalog AS catalog ON catalog.id = queries.catalog_id
             GROUP BY queries.catalog_id, catalog.normalized_sql
             ORDER BY avg_duration_ms DESC, max_duration_ms DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'normalized_sql' => (string) $row['normalized_sql'],
            'executions' => (int) $row['executions'],
            'avg_duration_ms' => (float) $row['avg_duration_ms'],
            'max_duration_ms' => (float) $row['max_duration_ms'],
        ], $statement->fetchAll());
    }

    /** @return list<array{name: string, executions: int, avg_duration_ms: float, max_duration_ms: float}> */
    public function getTopSpans(int $limit = 10): array
    {
        $statement = $this->pdo->prepare(
            'SELECT name,
                    COUNT(*) AS executions,
                    AVG(duration_ms) AS avg_duration_ms,
                    MAX(duration_ms) AS max_duration_ms
             FROM pulse_spans
             GROUP BY name
             ORDER BY avg_duration_ms DESC, max_duration_ms DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'executions' => (int) $row['executions'],
            'avg_duration_ms' => (float) $row['avg_duration_ms'],
            'max_duration_ms' => (float) $row['max_duration_ms'],
        ], $statement->fetchAll());
    }

    /** @return list<array{message: string, file: string, line: int, created_at: string}> */
    public function getLatestExceptions(int $limit = 10): array
    {
        $statement = $this->pdo->prepare(
            'SELECT message, file, line, created_at
             FROM pulse_exceptions
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'message' => (string) $row['message'],
            'file' => (string) $row['file'],
            'line' => (int) $row['line'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    /** @return list<array{minute: string, requests: int}> */
    public function getRequestTimeline(): array
    {
        $statement = $this->pdo->query(
            "WITH RECURSIVE minute_series(minute) AS (
                SELECT strftime('%Y-%m-%d %H:%M:00', 'now', '-59 minutes')
                UNION ALL
                SELECT strftime('%Y-%m-%d %H:%M:00', minute, '+1 minute')
                FROM minute_series
                WHERE minute < strftime('%Y-%m-%d %H:%M:00', 'now')
            ), request_counts AS (
                SELECT strftime('%Y-%m-%d %H:%M:00', created_at) AS minute,
                       COUNT(*) AS request_count
                FROM pulse_requests
                WHERE created_at >= strftime('%Y-%m-%d %H:%M:00', 'now', '-59 minutes')
                GROUP BY minute
            )
            SELECT minute_series.minute,
                   COALESCE(request_counts.request_count, 0) AS requests
            FROM minute_series
            LEFT JOIN request_counts ON request_counts.minute = minute_series.minute
            ORDER BY minute_series.minute"
        );

        return array_map(static fn (array $row): array => [
            'minute' => (string) $row['minute'],
            'requests' => (int) $row['requests'],
        ], $statement->fetchAll());
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 100));
    }
}