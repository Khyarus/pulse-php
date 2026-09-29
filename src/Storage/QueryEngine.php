<?php

declare(strict_types=1);

namespace PulsePHP\Storage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class QueryEngine
{
    private const CONTEXT_TABLES = [
        'pulse_requests',
        'pulse_metrics',
        'pulse_spans',
        'pulse_exceptions',
        'pulse_queries',
        'pulse_outbound_requests',
    ];

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

    /**
     * @return array{
     *     requests_total: int,
     *     avg_response_ms: float,
     *     peak_memory_bytes: int,
     *     exceptions_total: int,
     *     exceptions_today: int
     * }
     */
    public function getSummaryStats(
        string $period = 'all',
        ?string $service = null,
        ?string $route = null
    ): array {
        $parameters = [];
        $requestFilter = $this->filterSql('requests', 'requests', $period, $service, $route, $parameters);
        $exceptionFilter = $this->filterSql(
            'exceptions',
            'exceptions',
            $period,
            $service,
            $route,
            $parameters
        );
        $todayFilter = $this->filterSql(
            'today_exceptions',
            'today',
            'all',
            $service,
            $route,
            $parameters,
            false
        );
        $statement = $this->pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM pulse_requests AS requests WHERE 1 = 1 {$requestFilter}) AS requests_total,
                (SELECT COALESCE(AVG(requests.duration_ms), 0) FROM pulse_requests AS requests WHERE 1 = 1 {$requestFilter}) AS avg_response_ms,
                (SELECT COALESCE(MAX(requests.memory_bytes), 0) FROM pulse_requests AS requests WHERE 1 = 1 {$requestFilter}) AS peak_memory_bytes,
                (SELECT COUNT(*) FROM pulse_exceptions AS exceptions WHERE 1 = 1 {$exceptionFilter}) AS exceptions_total,
                (SELECT COUNT(*) FROM pulse_exceptions AS today_exceptions WHERE today_exceptions.created_at >= date('now') {$todayFilter}) AS exceptions_today"
        );
        $statement->execute($parameters);
        $stats = $statement->fetch();

        return [
            'requests_total' => (int) $stats['requests_total'],
            'avg_response_ms' => (float) $stats['avg_response_ms'],
            'peak_memory_bytes' => (int) $stats['peak_memory_bytes'],
            'exceptions_total' => (int) $stats['exceptions_total'],
            'exceptions_today' => (int) $stats['exceptions_today'],
        ];
    }

    /** @return list<array{normalized_sql: string, service: string, route: string, executions: int, avg_duration_ms: float, max_duration_ms: float}> */
    public function getSlowestQueries(
        int $limit = 10,
        string $period = 'all',
        ?string $service = null,
        ?string $route = null
    ): array {
        $parameters = [];
        $filters = $this->filterSql('queries', 'queries', $period, $service, $route, $parameters);
        $statement = $this->pdo->prepare(
            'SELECT catalog.normalized_sql,
                    queries.service,
                    queries.route,
                    COUNT(queries.id) AS executions,
                    AVG(queries.duration_ms) AS avg_duration_ms,
                    MAX(queries.duration_ms) AS max_duration_ms
             FROM pulse_queries AS queries
             INNER JOIN pulse_catalog AS catalog ON catalog.id = queries.catalog_id
             WHERE 1 = 1 ' . $filters . '
             GROUP BY queries.catalog_id, catalog.normalized_sql, queries.service, queries.route
             ORDER BY avg_duration_ms DESC, max_duration_ms DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'normalized_sql' => (string) $row['normalized_sql'],
            'service' => (string) $row['service'],
            'route' => (string) $row['route'],
            'executions' => (int) $row['executions'],
            'avg_duration_ms' => (float) $row['avg_duration_ms'],
            'max_duration_ms' => (float) $row['max_duration_ms'],
        ], $statement->fetchAll());
    }

    /** @return list<array{name: string, service: string, route: string, executions: int, avg_duration_ms: float, max_duration_ms: float}> */
    public function getTopSpans(
        int $limit = 10,
        string $period = 'all',
        ?string $service = null,
        ?string $route = null
    ): array {
        $parameters = [];
        $filters = $this->filterSql('spans', 'spans', $period, $service, $route, $parameters);
        $statement = $this->pdo->prepare(
            'SELECT name,
                    service,
                    route,
                    COUNT(*) AS executions,
                    AVG(duration_ms) AS avg_duration_ms,
                    MAX(duration_ms) AS max_duration_ms
             FROM pulse_spans AS spans
             WHERE 1 = 1 ' . $filters . '
             GROUP BY name, service, route
             ORDER BY avg_duration_ms DESC, max_duration_ms DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'service' => (string) $row['service'],
            'route' => (string) $row['route'],
            'executions' => (int) $row['executions'],
            'avg_duration_ms' => (float) $row['avg_duration_ms'],
            'max_duration_ms' => (float) $row['max_duration_ms'],
        ], $statement->fetchAll());
    }

    /** @return list<array{message: string, file: string, line: int, service: string, route: string, created_at: string}> */
    public function getLatestExceptions(
        int $limit = 10,
        string $period = 'all',
        ?string $service = null,
        ?string $route = null
    ): array {
        $parameters = [];
        $filters = $this->filterSql('exceptions', 'exceptions', $period, $service, $route, $parameters);
        $statement = $this->pdo->prepare(
            'SELECT message, file, line, service, route, created_at
             FROM pulse_exceptions AS exceptions
             WHERE 1 = 1 ' . $filters . '
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'message' => (string) $row['message'],
            'file' => (string) $row['file'],
            'line' => (int) $row['line'],
            'service' => (string) $row['service'],
            'route' => (string) $row['route'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    /** @return list<array{minute: string, requests: int}> */
    public function getRequestTimeline(
        string $period = '1h',
        ?string $service = null,
        ?string $route = null
    ): array {
        return array_map(static fn (array $row): array => [
            'minute' => $row['minute'],
            'requests' => $row['count'],
        ], $this->getTimeline('pulse_requests', 'requests', 'requests', $period, $service, $route));
    }

    /** @return list<array{minute: string, exceptions: int}> */
    public function getExceptionTimeline(
        string $period = '1h',
        ?string $service = null,
        ?string $route = null
    ): array {
        return array_map(static fn (array $row): array => [
            'minute' => $row['minute'],
            'exceptions' => $row['count'],
        ], $this->getTimeline('pulse_exceptions', 'exceptions', 'exceptions', $period, $service, $route));
    }

    /** @return list<array{url: string, method: string, status_code: int, duration_ms: float, service: string, route: string, created_at: string}> */
    public function getRecentRequests(
        int $limit = 100,
        string $period = '1h',
        ?string $service = null,
        ?string $route = null
    ): array {
        return $this->getRecentRows('pulse_requests', $limit, $period, $service, $route);
    }

    /** @return list<array{url: string, method: string, status_code: int, duration_ms: float, service: string, route: string, created_at: string}> */
    public function getOutboundRequests(
        int $limit = 100,
        string $period = '1h',
        ?string $service = null,
        ?string $route = null
    ): array {
        return $this->getRecentRows('pulse_outbound_requests', $limit, $period, $service, $route);
    }

    /** @return list<string> */
    public function getAvailableServices(): array
    {
        $queries = array_map(
            static fn (string $table): string => 'SELECT service FROM ' . $table,
            self::CONTEXT_TABLES
        );
        $statement = $this->pdo->query(
            'SELECT DISTINCT service FROM (' . implode(' UNION ALL ', $queries) . ') '
            . "WHERE service <> '' ORDER BY service"
        );

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public function getAvailableRoutes(?string $service = null): array
    {
        $queries = [];
        $parameters = [];
        foreach (self::CONTEXT_TABLES as $index => $table) {
            $serviceFilter = '';
            if ($service !== null && $service !== '') {
                $parameter = ':service_' . $index;
                $serviceFilter = ' WHERE service = ' . $parameter;
                $parameters[$parameter] = $service;
            }
            $queries[] = 'SELECT route FROM ' . $table . $serviceFilter;
        }

        $statement = $this->pdo->prepare(
            'SELECT DISTINCT route FROM (' . implode(' UNION ALL ', $queries) . ') '
            . "WHERE route <> '' ORDER BY route"
        );
        $statement->execute($parameters);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array{
     *     service: string,
     *     route: string,
     *     method: string,
     *     total_requests: int,
     *     avg_duration_ms: float,
     *     error_rate_percent: float,
     *     unhandled_exceptions: int,
     *     timeline: list<array{timestamp: string, requests: int, avg_duration_ms: float}>
     * }>
     */
    public function getMetricsByRoute(string $from, string $to, ?string $service = null): array
    {
        $from = $this->normalizeDateTime($from);
        $to = $this->normalizeDateTime($to);
        $parameters = [':from' => $from, ':to' => $to];
        $serviceRequestFilter = '';
        $serviceExceptionFilter = '';
        if ($service !== null && $service !== '') {
            $serviceRequestFilter = ' AND requests.service = :request_service';
            $serviceExceptionFilter = ' AND exceptions.service = :exception_service';
            $parameters[':request_service'] = $service;
            $parameters[':exception_service'] = $service;
        }

        $summary = $this->pdo->prepare(
            'SELECT requests.service, requests.route, requests.method,
                    COUNT(*) AS total_requests,
                    AVG(requests.duration_ms) AS avg_duration_ms,
                    100.0 * SUM(CASE WHEN requests.status_code >= 400 THEN 1 ELSE 0 END) / COUNT(*) AS error_rate_percent,
                    COALESCE(unhandled.count, 0) AS unhandled_exceptions
             FROM pulse_requests AS requests
             LEFT JOIN (
                 SELECT service, route, method, COUNT(*) AS count
                 FROM pulse_exceptions AS exceptions
                 WHERE exceptions.unhandled = 1
                   AND exceptions.created_at >= :exception_from
                   AND exceptions.created_at <= :exception_to' . $serviceExceptionFilter . '
                 GROUP BY service, route, method
             ) AS unhandled
               ON unhandled.service = requests.service
              AND unhandled.route = requests.route
              AND (unhandled.method = requests.method OR unhandled.method = \'\')
             WHERE requests.created_at >= :from
               AND requests.created_at <= :to' . $serviceRequestFilter . '
             GROUP BY requests.service, requests.route, requests.method
             ORDER BY total_requests DESC, requests.route, requests.method'
        );
        $summaryParameters = $parameters + [':exception_from' => $from, ':exception_to' => $to];
        $summary->execute($summaryParameters);
        $rows = $summary->fetchAll();

        $bucketFormat = $this->routeBucketFormat($from, $to);
        $timeline = $this->pdo->prepare(
            'SELECT service, route, method, strftime(:bucket_format, created_at) AS bucket,
                    COUNT(*) AS requests, AVG(duration_ms) AS avg_duration_ms
             FROM pulse_requests
             WHERE created_at >= :from AND created_at <= :to'
            . ($service !== null && $service !== '' ? ' AND service = :service' : '') . '
             GROUP BY service, route, method, bucket
             ORDER BY bucket'
        );
        $timelineParameters = [
            ':bucket_format' => $bucketFormat,
            ':from' => $from,
            ':to' => $to,
        ];
        if ($service !== null && $service !== '') {
            $timelineParameters[':service'] = $service;
        }
        $timeline->execute($timelineParameters);

        $seriesByRoute = [];
        foreach ($timeline->fetchAll() as $point) {
            $key = json_encode(
                [$point['service'], $point['route'], $point['method']],
                JSON_THROW_ON_ERROR
            );
            $seriesByRoute[$key][] = [
                'timestamp' => (string) $point['bucket'],
                'requests' => (int) $point['requests'],
                'avg_duration_ms' => (float) $point['avg_duration_ms'],
            ];
        }

        return array_map(static function (array $row) use ($seriesByRoute): array {
            $key = json_encode(
                [$row['service'], $row['route'], $row['method']],
                JSON_THROW_ON_ERROR
            );

            return [
                'service' => (string) $row['service'],
                'route' => (string) $row['route'],
                'method' => (string) $row['method'],
                'total_requests' => (int) $row['total_requests'],
                'avg_duration_ms' => (float) $row['avg_duration_ms'],
                'error_rate_percent' => (float) $row['error_rate_percent'],
                'unhandled_exceptions' => (int) $row['unhandled_exceptions'],
                'timeline' => $seriesByRoute[$key] ?? [],
            ];
        }, $rows);
    }

    /** @return list<array{url: string, status_code: int, duration_ms: float, created_at: string}> */
    public function getSlowestRequestsByRoute(
        string $from,
        string $to,
        string $service,
        string $route,
        string $method,
        int $limit = 5
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT url, status_code, duration_ms, created_at
             FROM pulse_requests
             WHERE created_at >= :from AND created_at <= :to
               AND service = :service AND route = :route AND method = :method
             ORDER BY duration_ms DESC, created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':from', $this->normalizeDateTime($from));
        $statement->bindValue(':to', $this->normalizeDateTime($to));
        $statement->bindValue(':service', $service);
        $statement->bindValue(':route', $route);
        $statement->bindValue(':method', $method);
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'url' => (string) $row['url'],
            'status_code' => (int) $row['status_code'],
            'duration_ms' => (float) $row['duration_ms'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    /** @return list<array{minute: string, count: int}> */
    private function getTimeline(
        string $table,
        string $alias,
        string $countName,
        string $period,
        ?string $service,
        ?string $route
    ): array {
        $parameters = [':bucket_format' => $this->bucketFormat($period)];
        $filters = $this->filterSql($alias, 'timeline', $period, $service, $route, $parameters);
        $statement = $this->pdo->prepare(
            'SELECT strftime(:bucket_format, ' . $alias . '.created_at) AS minute, '
            . 'COUNT(*) AS count FROM ' . $table . ' AS ' . $alias
            . ' WHERE 1 = 1 ' . $filters . ' GROUP BY minute ORDER BY minute'
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'minute' => (string) $row['minute'],
            'count' => (int) $row['count'],
        ], $statement->fetchAll());
    }

    /** @return list<array{url: string, method: string, status_code: int, duration_ms: float, service: string, route: string, created_at: string}> */
    private function getRecentRows(
        string $table,
        int $limit,
        string $period,
        ?string $service,
        ?string $route
    ): array {
        $parameters = [];
        $filters = $this->filterSql('records', 'records', $period, $service, $route, $parameters);
        $statement = $this->pdo->prepare(
            'SELECT url, method, status_code, duration_ms, service, route, created_at '
            . 'FROM ' . $table . ' AS records WHERE 1 = 1 ' . $filters
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'url' => (string) $row['url'],
            'method' => (string) $row['method'],
            'status_code' => (int) $row['status_code'],
            'duration_ms' => (float) $row['duration_ms'],
            'service' => (string) $row['service'],
            'route' => (string) $row['route'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    private function filterSql(
        string $alias,
        string $prefix,
        string $period,
        ?string $service,
        ?string $route,
        array &$parameters,
        bool $includePeriod = true
    ): string {
        $filters = '';
        $modifier = $includePeriod ? $this->periodModifier($period) : null;
        if ($modifier !== null) {
            $parameter = ':' . $prefix . '_period';
            $filters .= " AND {$alias}.created_at >= datetime('now', {$parameter})";
            $parameters[$parameter] = $modifier;
        }
        if ($service !== null && $service !== '') {
            $parameter = ':' . $prefix . '_service';
            $filters .= " AND {$alias}.service = {$parameter}";
            $parameters[$parameter] = $service;
        }
        if ($route !== null && $route !== '') {
            $parameter = ':' . $prefix . '_route';
            $filters .= " AND {$alias}.route = {$parameter}";
            $parameters[$parameter] = $route;
        }

        return $filters;
    }

    private function periodModifier(string $period): ?string
    {
        return match ($period) {
            '15m' => '-15 minutes',
            '1h' => '-1 hour',
            '24h' => '-24 hours',
            '7d' => '-7 days',
            default => null,
        };
    }

    private function bucketFormat(string $period): string
    {
        return match ($period) {
            '24h' => '%Y-%m-%d %H:00:00',
            '7d' => '%Y-%m-%d 00:00:00',
            default => '%Y-%m-%d %H:%M:00',
        };
    }

    private function normalizeDateTime(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Date filters must be valid date/time values.', 0, $exception);
        }
    }

    private function routeBucketFormat(string $from, string $to): string
    {
        $rangeSeconds = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->getTimestamp()
            - (new DateTimeImmutable($from, new DateTimeZone('UTC')))->getTimestamp();

        if ($rangeSeconds <= 3 * 60 * 60) {
            return '%Y-%m-%d %H:%M:00';
        }
        if ($rangeSeconds <= 2 * 24 * 60 * 60) {
            return '%Y-%m-%d %H:00:00';
        }

        return '%Y-%m-%d 00:00:00';
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 100));
    }
}
