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

    public function __construct(private string $dbPath)
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
     *     p95_response_ms: float,
     *     p99_response_ms: float,
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

        $percentileParameters = [];
        $percentileFilter = $this->filterSql('requests', 'pct', $period, $service, $route, $percentileParameters);
        $percentiles = $this->pdo->prepare(
            'SELECT MIN(CASE WHEN position >= 0.95 * total THEN duration_ms END) AS p95,
                    MIN(CASE WHEN position >= 0.99 * total THEN duration_ms END) AS p99
             FROM (
                 SELECT requests.duration_ms,
                        ROW_NUMBER() OVER (ORDER BY requests.duration_ms) AS position,
                        COUNT(*) OVER () AS total
                 FROM pulse_requests AS requests
                 WHERE 1 = 1 ' . $percentileFilter . '
             )'
        );
        $percentiles->execute($percentileParameters);
        $percentileRow = $percentiles->fetch() ?: [];

        return [
            'requests_total' => (int) $stats['requests_total'],
            'avg_response_ms' => (float) $stats['avg_response_ms'],
            'p95_response_ms' => (float) ($percentileRow['p95'] ?? 0),
            'p99_response_ms' => (float) ($percentileRow['p99'] ?? 0),
            'peak_memory_bytes' => (int) $stats['peak_memory_bytes'],
            'exceptions_total' => (int) $stats['exceptions_total'],
            'exceptions_today' => (int) $stats['exceptions_today'],
        ];
    }

    /**
     * Aggregates for the last $minutes minutes, used by alert rules.
     *
     * @return array{requests: int, server_errors: int, error_rate_percent: float, exceptions: int, p95_ms: float}
     */
    public function getWindowStats(int $minutes): array
    {
        $modifier = sprintf('-%d minutes', max(1, $minutes));
        $statement = $this->pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM pulse_requests WHERE created_at >= datetime('now', :requests_window)) AS requests,
                (SELECT COUNT(*) FROM pulse_requests WHERE status_code >= 500 AND created_at >= datetime('now', :errors_window)) AS server_errors,
                (SELECT COUNT(*) FROM pulse_exceptions WHERE created_at >= datetime('now', :exceptions_window)) AS exceptions,
                (SELECT MIN(CASE WHEN position >= 0.95 * total THEN duration_ms END) FROM (
                    SELECT duration_ms,
                           ROW_NUMBER() OVER (ORDER BY duration_ms) AS position,
                           COUNT(*) OVER () AS total
                    FROM pulse_requests WHERE created_at >= datetime('now', :p95_window)
                )) AS p95_ms"
        );
        $statement->execute([
            ':requests_window' => $modifier,
            ':errors_window' => $modifier,
            ':exceptions_window' => $modifier,
            ':p95_window' => $modifier,
        ]);
        $row = $statement->fetch();
        $requests = (int) $row['requests'];
        $serverErrors = (int) $row['server_errors'];

        return [
            'requests' => $requests,
            'server_errors' => $serverErrors,
            'error_rate_percent' => $requests > 0 ? 100.0 * $serverErrors / $requests : 0.0,
            'exceptions' => (int) $row['exceptions'],
            'p95_ms' => (float) ($row['p95_ms'] ?? 0),
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

    /** @return list<array{class: string, message: string, file: string, line: int, service: string, route: string, trace_id: string, created_at: string}> */
    public function getLatestExceptions(
        int $limit = 10,
        string $period = 'all',
        ?string $service = null,
        ?string $route = null
    ): array {
        $parameters = [];
        $filters = $this->filterSql('exceptions', 'exceptions', $period, $service, $route, $parameters);
        $statement = $this->pdo->prepare(
            'SELECT class, message, file, line, service, route, trace_id, created_at
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
            'class' => (string) $row['class'],
            'message' => (string) $row['message'],
            'file' => (string) $row['file'],
            'line' => (int) $row['line'],
            'service' => (string) $row['service'],
            'route' => (string) $row['route'],
            'trace_id' => (string) $row['trace_id'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    /**
     * Groups exceptions by fingerprint (class + file + line).
     *
     * Status is `open`, `resolved`, `ignored` or `regressed` (resolved, then
     * seen again). Filtering by `open` also returns regressed groups.
     *
     * @return list<array{fingerprint: string, class: string, message: string, file: string, line: int, occurrences: int, unhandled: int, routes: int, first_seen: string, last_seen: string, last_trace_id: string, status: string}>
     */
    public function getExceptionGroups(
        int $limit = 50,
        string $period = 'all',
        ?string $service = null,
        ?string $route = null,
        ?string $status = null,
        ?string $fingerprint = null
    ): array {
        $parameters = [];
        $filters = $this->filterSql('exceptions', 'groups', $period, $service, $route, $parameters);
        if ($fingerprint !== null) {
            $filters .= ' AND exceptions.fingerprint = :group_fingerprint';
            $parameters[':group_fingerprint'] = $fingerprint;
        }
        $statusSql = "CASE
                WHEN states.status IS NULL THEN 'open'
                WHEN states.status = 'resolved' AND grouped.last_seen > states.updated_at THEN 'regressed'
                ELSE states.status
            END";
        $statusFilter = match ($status) {
            'open' => " WHERE {$statusSql} IN ('open', 'regressed')",
            'resolved', 'ignored', 'regressed' => " WHERE {$statusSql} = :status_filter",
            default => '',
        };
        if (in_array($status, ['resolved', 'ignored', 'regressed'], true)) {
            $parameters[':status_filter'] = $status;
        }
        $statement = $this->pdo->prepare(
            'SELECT grouped.*,
                    latest.message, latest.trace_id AS last_trace_id,
                    ' . $statusSql . ' AS status
             FROM (
                 SELECT exceptions.fingerprint,
                        MAX(exceptions.class) AS class,
                        MAX(exceptions.file) AS file,
                        MAX(exceptions.line) AS line,
                        COUNT(*) AS occurrences,
                        SUM(exceptions.unhandled) AS unhandled,
                        COUNT(DISTINCT exceptions.route) AS routes,
                        MIN(exceptions.created_at) AS first_seen,
                        MAX(exceptions.created_at) AS last_seen,
                        MAX(exceptions.id) AS last_id
                 FROM pulse_exceptions AS exceptions
                 WHERE 1 = 1 ' . $filters . '
                 GROUP BY exceptions.fingerprint
             ) AS grouped
             INNER JOIN pulse_exceptions AS latest ON latest.id = grouped.last_id
             LEFT JOIN pulse_exception_states AS states ON states.fingerprint = grouped.fingerprint
             ' . $statusFilter . '
             ORDER BY grouped.last_seen DESC, grouped.occurrences DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'fingerprint' => (string) $row['fingerprint'],
            'class' => (string) $row['class'],
            'message' => (string) $row['message'],
            'file' => (string) $row['file'],
            'line' => (int) $row['line'],
            'occurrences' => (int) $row['occurrences'],
            'unhandled' => (int) $row['unhandled'],
            'routes' => (int) $row['routes'],
            'first_seen' => (string) $row['first_seen'],
            'last_seen' => (string) $row['last_seen'],
            'last_trace_id' => (string) $row['last_trace_id'],
            'status' => (string) $row['status'],
        ], $statement->fetchAll());
    }

    /**
     * Finds query patterns executed repeatedly inside the same trace
     * (request or job) — the classic N+1 symptom.
     *
     * @return list<array{normalized_sql: string, service: string, route: string, affected_traces: int, max_per_trace: int, avg_per_trace: float, sample_trace_id: string}>
     */
    public function getNPlusOneQueries(
        int $threshold = 5,
        int $limit = 20,
        string $period = 'all',
        ?string $service = null,
        ?string $route = null
    ): array {
        $parameters = [];
        $filters = $this->filterSql('queries', 'nplus', $period, $service, $route, $parameters);
        $statement = $this->pdo->prepare(
            "SELECT catalog.normalized_sql,
                    per_trace.service,
                    per_trace.route,
                    COUNT(*) AS affected_traces,
                    MAX(per_trace.executions) AS max_per_trace,
                    AVG(per_trace.executions) AS avg_per_trace,
                    MAX(per_trace.trace_id) AS sample_trace_id
             FROM (
                 SELECT queries.trace_id, queries.catalog_id, queries.service, queries.route,
                        COUNT(*) AS executions
                 FROM pulse_queries AS queries
                 WHERE queries.trace_id <> '' " . $filters . '
                 GROUP BY queries.trace_id, queries.catalog_id, queries.service, queries.route
                 HAVING COUNT(*) >= :threshold
             ) AS per_trace
             INNER JOIN pulse_catalog AS catalog ON catalog.id = per_trace.catalog_id
             GROUP BY per_trace.catalog_id, catalog.normalized_sql, per_trace.service, per_trace.route
             ORDER BY max_per_trace DESC, affected_traces DESC
             LIMIT :limit'
        );
        $statement->bindValue(':threshold', max(2, $threshold), PDO::PARAM_INT);
        $statement->bindValue(':limit', $this->normalizeLimit($limit), PDO::PARAM_INT);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'normalized_sql' => (string) $row['normalized_sql'],
            'service' => (string) $row['service'],
            'route' => (string) $row['route'],
            'affected_traces' => (int) $row['affected_traces'],
            'max_per_trace' => (int) $row['max_per_trace'],
            'avg_per_trace' => (float) $row['avg_per_trace'],
            'sample_trace_id' => (string) $row['sample_trace_id'],
        ], $statement->fetchAll());
    }

    /**
     * Everything recorded for one trace, in recording order.
     *
     * @return array{
     *     trace_id: string,
     *     request: array<string, mixed>|null,
     *     spans: list<array<string, mixed>>,
     *     queries: list<array<string, mixed>>,
     *     outbound: list<array<string, mixed>>,
     *     exceptions: list<array<string, mixed>>
     * }|null
     */
    public function getTrace(string $traceId): ?array
    {
        if ($traceId === '' || preg_match('/^[a-f0-9]{8,64}$/', $traceId) !== 1) {
            return null;
        }

        $fetch = function (string $sql) use ($traceId): array {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([':trace_id' => $traceId]);

            return $statement->fetchAll();
        };

        $requests = $fetch(
            'SELECT url, method, status_code, duration_ms, memory_bytes, ip, service, route, created_at
             FROM pulse_requests WHERE trace_id = :trace_id ORDER BY id LIMIT 1'
        );
        $spans = $fetch(
            'SELECT name, duration_ms, memory_bytes, offset_ms, created_at
             FROM pulse_spans WHERE trace_id = :trace_id ORDER BY id LIMIT 500'
        );
        $queries = $fetch(
            'SELECT catalog.normalized_sql, queries.duration_ms, queries.offset_ms, queries.created_at
             FROM pulse_queries AS queries
             INNER JOIN pulse_catalog AS catalog ON catalog.id = queries.catalog_id
             WHERE queries.trace_id = :trace_id ORDER BY queries.id LIMIT 500'
        );
        $outbound = $fetch(
            'SELECT method, status_code, url, duration_ms, offset_ms, created_at
             FROM pulse_outbound_requests WHERE trace_id = :trace_id ORDER BY id LIMIT 500'
        );
        $exceptions = $fetch(
            'SELECT class, fingerprint, message, file, line, trace, unhandled, created_at
             FROM pulse_exceptions WHERE trace_id = :trace_id ORDER BY id LIMIT 500'
        );
        $logs = $fetch(
            'SELECT level, message, context, created_at
             FROM pulse_logs WHERE trace_id = :trace_id ORDER BY id LIMIT 500'
        );

        if ($requests === [] && $spans === [] && $queries === [] && $outbound === []
            && $exceptions === [] && $logs === []) {
            return null;
        }

        return [
            'trace_id' => $traceId,
            'request' => $requests[0] ?? null,
            'spans' => $spans,
            'queries' => $queries,
            'outbound' => $outbound,
            'exceptions' => $exceptions,
            'logs' => $logs,
        ];
    }

    /**
     * Headline numbers for an explicit UTC window [from, to).
     *
     * @return array{requests: int, server_errors: int, error_rate_percent: float, avg_ms: float, p95_ms: float, exceptions: int}
     */
    public function getWindowMetrics(string $from, string $to, ?string $service = null, ?string $route = null): array
    {
        $parameters = [':from' => $this->normalizeDateTime($from), ':to' => $this->normalizeDateTime($to)];
        $scope = '';
        if ($service !== null && $service !== '') {
            $scope .= ' AND service = :service';
            $parameters[':service'] = $service;
        }
        if ($route !== null && $route !== '') {
            $scope .= ' AND route = :route';
            $parameters[':route'] = $route;
        }
        $window = 'created_at >= :from AND created_at < :to' . $scope;

        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS requests,
                    COALESCE(SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END), 0) AS server_errors,
                    COALESCE(AVG(duration_ms), 0) AS avg_ms,
                    (SELECT MIN(CASE WHEN position >= 0.95 * total THEN duration_ms END) FROM (
                        SELECT duration_ms,
                               ROW_NUMBER() OVER (ORDER BY duration_ms) AS position,
                               COUNT(*) OVER () AS total
                        FROM pulse_requests WHERE {$window}
                    )) AS p95_ms,
                    (SELECT COUNT(*) FROM pulse_exceptions WHERE {$window}) AS exceptions
             FROM pulse_requests WHERE {$window}"
        );
        $statement->execute($parameters);
        $row = $statement->fetch();
        $requests = (int) $row['requests'];

        return [
            'requests' => $requests,
            'server_errors' => (int) $row['server_errors'],
            'error_rate_percent' => $requests > 0 ? 100.0 * (int) $row['server_errors'] / $requests : 0.0,
            'avg_ms' => (float) $row['avg_ms'],
            'p95_ms' => (float) ($row['p95_ms'] ?? 0),
            'exceptions' => (int) $row['exceptions'],
        ];
    }

    /**
     * Paginated, filterable request log.
     *
     * @param array{period?: string, service?: ?string, route?: ?string, status?: ?string, method?: ?string, min_ms?: mixed, search?: ?string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function searchRequests(array $filters, int $page = 1, int $perPage = 50): array
    {
        $parameters = [];
        $where = 'WHERE 1 = 1' . $this->filterSql(
            'requests',
            'search',
            (string) ($filters['period'] ?? '1h'),
            $filters['service'] ?? null,
            $filters['route'] ?? null,
            $parameters
        );

        $status = $filters['status'] ?? null;
        if (is_string($status) && preg_match('/^([1-5])xx$/', $status, $matches) === 1) {
            $where .= ' AND requests.status_code >= :status_low AND requests.status_code < :status_high';
            $parameters[':status_low'] = (int) $matches[1] * 100;
            $parameters[':status_high'] = (int) $matches[1] * 100 + 100;
        }
        $method = $filters['method'] ?? null;
        if (is_string($method) && $method !== '') {
            $where .= ' AND requests.method = :method';
            $parameters[':method'] = strtoupper($method);
        }
        $minMs = $filters['min_ms'] ?? null;
        if (is_numeric($minMs) && (float) $minMs > 0) {
            $where .= ' AND requests.duration_ms >= :min_ms';
            $parameters[':min_ms'] = (float) $minMs;
        }
        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $where .= " AND (requests.url LIKE :search ESCAPE '!' OR requests.route LIKE :search_route ESCAPE '!')";
            $parameters[':search'] = $this->likePattern($search);
            $parameters[':search_route'] = $this->likePattern($search);
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM pulse_requests AS requests ' . $where);
        $count->execute($parameters);

        $perPage = max(1, min($perPage, 200));
        $statement = $this->pdo->prepare(
            'SELECT url, method, status_code, duration_ms, memory_bytes, service, route, trace_id, created_at
             FROM pulse_requests AS requests ' . $where . '
             ORDER BY requests.created_at DESC, requests.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', (max(1, $page) - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * One exception group in detail.
     *
     * @return array{group: array<string, mixed>, occurrences: list<array<string, mixed>>, routes: list<array{route: string, method: string, count: int}>, timeline: list<array{minute: string, count: int}>, stack: string}|null
     */
    public function getExceptionGroup(string $fingerprint, string $period = '7d'): ?array
    {
        $groups = $this->getExceptionGroups(1, 'all', null, null, null, $fingerprint);
        if ($groups === []) {
            return null;
        }

        $occurrences = $this->pdo->prepare(
            'SELECT message, route, method, service, trace_id, unhandled, created_at
             FROM pulse_exceptions WHERE fingerprint = :fingerprint
             ORDER BY id DESC LIMIT 50'
        );
        $occurrences->execute([':fingerprint' => $fingerprint]);

        $routes = $this->pdo->prepare(
            'SELECT route, method, COUNT(*) AS count
             FROM pulse_exceptions WHERE fingerprint = :fingerprint
             GROUP BY route, method ORDER BY count DESC LIMIT 20'
        );
        $routes->execute([':fingerprint' => $fingerprint]);

        $stack = $this->pdo->prepare(
            'SELECT trace FROM pulse_exceptions WHERE fingerprint = :fingerprint ORDER BY id DESC LIMIT 1'
        );
        $stack->execute([':fingerprint' => $fingerprint]);

        $parameters = [':bucket_format' => $this->bucketFormat($period), ':fingerprint' => $fingerprint];
        $timelineFilter = $this->filterSql('exceptions', 'detail', $period, null, null, $parameters);
        $timeline = $this->pdo->prepare(
            'SELECT strftime(:bucket_format, exceptions.created_at) AS minute, COUNT(*) AS count
             FROM pulse_exceptions AS exceptions
             WHERE exceptions.fingerprint = :fingerprint ' . $timelineFilter . '
             GROUP BY minute ORDER BY minute'
        );
        $timeline->execute($parameters);

        return [
            'group' => $groups[0],
            'occurrences' => $occurrences->fetchAll(),
            'routes' => array_map(static fn (array $row): array => [
                'route' => (string) $row['route'],
                'method' => (string) $row['method'],
                'count' => (int) $row['count'],
            ], $routes->fetchAll()),
            'timeline' => array_map(static fn (array $row): array => [
                'minute' => (string) $row['minute'],
                'count' => (int) $row['count'],
            ], $timeline->fetchAll()),
            'stack' => (string) $stack->fetchColumn(),
        ];
    }

    /**
     * Queued jobs, aggregated by class. A run failed when an exception was
     * recorded inside its trace.
     *
     * @return list<array{name: string, runs: int, avg_ms: float, p95_ms: float, max_ms: float, failures: int, last_run: string}>
     */
    public function getJobs(string $period = '24h', ?string $service = null): array
    {
        $parameters = [];
        $filters = $this->filterSql('spans', 'jobs', $period, $service, null, $parameters);
        $statement = $this->pdo->prepare(
            "SELECT name,
                    COUNT(*) AS runs,
                    AVG(duration_ms) AS avg_ms,
                    MAX(duration_ms) AS max_ms,
                    MIN(CASE WHEN position >= 0.95 * total THEN duration_ms END) AS p95_ms,
                    MAX(created_at) AS last_run,
                    SUM(failed) AS failures
             FROM (
                 SELECT spans.name, spans.duration_ms, spans.created_at,
                        ROW_NUMBER() OVER (PARTITION BY spans.name ORDER BY spans.duration_ms) AS position,
                        COUNT(*) OVER (PARTITION BY spans.name) AS total,
                        EXISTS (
                            SELECT 1 FROM pulse_exceptions AS exceptions
                            WHERE spans.trace_id <> '' AND exceptions.trace_id = spans.trace_id
                        ) AS failed
                 FROM pulse_spans AS spans
                 WHERE spans.name LIKE 'job:%' " . $filters . '
             )
             GROUP BY name
             ORDER BY runs DESC'
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'name' => substr((string) $row['name'], 4),
            'runs' => (int) $row['runs'],
            'avg_ms' => (float) $row['avg_ms'],
            'p95_ms' => (float) $row['p95_ms'],
            'max_ms' => (float) $row['max_ms'],
            'failures' => (int) $row['failures'],
            'last_run' => (string) $row['last_run'],
        ], $statement->fetchAll());
    }

    /**
     * Outbound HTTP calls aggregated by destination host.
     *
     * @return list<array{host: string, calls: int, avg_ms: float, max_ms: float, failures: int, last_call: string}>
     */
    public function getOutboundHosts(string $period = '24h', ?string $service = null, ?string $route = null): array
    {
        $parameters = [];
        $filters = $this->filterSql('records', 'hosts', $period, $service, $route, $parameters);
        $statement = $this->pdo->prepare(
            "SELECT CASE WHEN instr(rest, '/') > 0 THEN substr(rest, 1, instr(rest, '/') - 1) ELSE rest END AS host,
                    COUNT(*) AS calls,
                    AVG(duration_ms) AS avg_ms,
                    MAX(duration_ms) AS max_ms,
                    SUM(CASE WHEN status_code = 0 OR status_code >= 500 THEN 1 ELSE 0 END) AS failures,
                    MAX(created_at) AS last_call
             FROM (
                 SELECT substr(records.url, instr(records.url, '://') + 3) AS rest,
                        records.status_code, records.duration_ms, records.created_at
                 FROM pulse_outbound_requests AS records
                 WHERE 1 = 1 " . $filters . '
             )
             GROUP BY host
             ORDER BY calls DESC'
        );
        $statement->execute($parameters);

        return array_map(static fn (array $row): array => [
            'host' => (string) $row['host'],
            'calls' => (int) $row['calls'],
            'avg_ms' => (float) $row['avg_ms'],
            'max_ms' => (float) $row['max_ms'],
            'failures' => (int) $row['failures'],
            'last_call' => (string) $row['last_call'],
        ], $statement->fetchAll());
    }

    /** @return list<array{level: string, message: string, context: string, service: string, route: string, trace_id: string, created_at: string}> */
    public function getLogs(
        int $limit = 100,
        string $period = '1h',
        ?string $service = null,
        ?string $route = null,
        ?string $level = null,
        ?string $search = null
    ): array {
        $parameters = [];
        $where = 'WHERE 1 = 1' . $this->filterSql('logs', 'logs', $period, $service, $route, $parameters);
        if ($level !== null && $level !== '') {
            $where .= ' AND logs.level = :level';
            $parameters[':level'] = strtolower($level);
        }
        if ($search !== null && trim($search) !== '') {
            $where .= " AND logs.message LIKE :search ESCAPE '!'";
            $parameters[':search'] = $this->likePattern($search);
        }
        $statement = $this->pdo->prepare(
            'SELECT level, message, context, service, route, trace_id, created_at
             FROM pulse_logs AS logs ' . $where . '
             ORDER BY logs.id DESC LIMIT :limit'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue(':limit', max(1, min($limit, 500)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array{version: string, service: string, created_at: string}> */
    public function getDeploys(string $period = '7d', ?string $service = null): array
    {
        $parameters = [];
        $filters = $this->filterSql('deploys', 'deploys', $period, $service, null, $parameters);
        $statement = $this->pdo->prepare(
            'SELECT version, service, created_at FROM pulse_deploys AS deploys
             WHERE 1 = 1 ' . $filters . ' ORDER BY created_at DESC, id DESC LIMIT 50'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /** @return array{path: string, size_bytes: int, tables: array<string, int>, oldest: ?string} */
    public function getStorageInfo(): array
    {
        $tables = [];
        foreach (array_merge(self::CONTEXT_TABLES, ['pulse_logs', 'pulse_deploys', 'pulse_catalog']) as $table) {
            $tables[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        $size = 0;
        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = $this->dbPath . $suffix;
            $size += is_file($file) ? (int) filesize($file) : 0;
        }
        $oldest = $this->pdo->query('SELECT MIN(created_at) FROM pulse_requests')->fetchColumn();

        return [
            'path' => $this->dbPath,
            'size_bytes' => $size,
            'tables' => $tables,
            'oldest' => is_string($oldest) ? $oldest : null,
        ];
    }

    private function likePattern(string $search): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)) . '%';
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

    /** @return list<array{url: string, method: string, status_code: int, duration_ms: float, service: string, route: string, trace_id: string, created_at: string}> */
    public function getRecentRequests(
        int $limit = 100,
        string $period = '1h',
        ?string $service = null,
        ?string $route = null
    ): array {
        return $this->getRecentRows('pulse_requests', $limit, $period, $service, $route);
    }

    /** @return list<array{url: string, method: string, status_code: int, duration_ms: float, service: string, route: string, trace_id: string, created_at: string}> */
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
     *     p95_duration_ms: float,
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

        $percentiles = $this->pdo->prepare(
            'SELECT service, route, method,
                    MIN(CASE WHEN position >= 0.95 * total THEN duration_ms END) AS p95
             FROM (
                 SELECT service, route, method, duration_ms,
                        ROW_NUMBER() OVER (PARTITION BY service, route, method ORDER BY duration_ms) AS position,
                        COUNT(*) OVER (PARTITION BY service, route, method) AS total
                 FROM pulse_requests
                 WHERE created_at >= :from AND created_at <= :to'
            . ($service !== null && $service !== '' ? ' AND service = :service' : '') . '
             )
             GROUP BY service, route, method'
        );
        $percentileParameters = [':from' => $from, ':to' => $to];
        if ($service !== null && $service !== '') {
            $percentileParameters[':service'] = $service;
        }
        $percentiles->execute($percentileParameters);
        $p95ByRoute = [];
        foreach ($percentiles->fetchAll() as $point) {
            $key = json_encode([$point['service'], $point['route'], $point['method']], JSON_THROW_ON_ERROR);
            $p95ByRoute[$key] = (float) $point['p95'];
        }

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

        return array_map(static function (array $row) use ($seriesByRoute, $p95ByRoute): array {
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
                'p95_duration_ms' => $p95ByRoute[$key] ?? 0.0,
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

    /** @return list<array{url: string, method: string, status_code: int, duration_ms: float, service: string, route: string, trace_id: string, created_at: string}> */
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
            'SELECT url, method, status_code, duration_ms, service, route, trace_id, created_at '
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
            'trace_id' => (string) $row['trace_id'],
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
