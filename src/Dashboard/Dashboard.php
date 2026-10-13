<?php

declare(strict_types=1);

namespace PulsePHP\Dashboard;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PulsePHP\Storage\QueryEngine;
use PulsePHP\Storage\SQLiteStorage;
use Throwable;

final class Dashboard
{
    private const PERIODS = [
        '15m' => 'Last 15 minutes',
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
    ];

    private const PREVIOUS_LABELS = [
        '15m' => 'previous 15 min',
        '1h' => 'previous hour',
        '24h' => 'previous day',
        '7d' => 'previous week',
    ];

    private const SCREENS = [
        'overview' => 'Overview',
        'requests' => 'Requests',
        'errors' => 'Errors',
        'queries' => 'Queries',
        'jobs' => 'Jobs',
        'outbound' => 'Outbound HTTP',
        'logs' => 'Logs',
        'settings' => 'Storage & settings',
    ];

    /** Public name => [file inside resources/assets, content type]. */
    private const ASSETS = [
        'app.css' => ['app.css', 'text/css; charset=UTF-8'],
        'app.js' => ['app.js', 'text/javascript; charset=UTF-8'],
        'chart.js' => ['chart.umd.min.js', 'text/javascript; charset=UTF-8'],
        'alpine.js' => ['alpine.min.js', 'text/javascript; charset=UTF-8'],
        'fonts/plex-sans-400.woff2' => ['fonts/plex-sans-400.woff2', 'font/woff2'],
        'fonts/plex-sans-500.woff2' => ['fonts/plex-sans-500.woff2', 'font/woff2'],
        'fonts/plex-sans-600.woff2' => ['fonts/plex-sans-600.woff2', 'font/woff2'],
        'fonts/plex-mono-400.woff2' => ['fonts/plex-mono-400.woff2', 'font/woff2'],
        'fonts/plex-mono-500.woff2' => ['fonts/plex-mono-500.woff2', 'font/woff2'],
    ];

    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
    private const CSRF_COOKIE = 'pulse_csrf';

    private ?array $basicAuth = null;
    private ?array $allowedIps = null;
    private ?Closure $authorizationCallback = null;
    private bool $authorizationConfigured = false;
    private QueryEngine $queries;
    private SQLiteStorage $storage;
    private string $dataEndpoint = 'dashboard-data.php';
    private int $nPlusOneThreshold = 5;
    private ?string $formTokenField = null;
    private ?string $formTokenValue = null;

    /** @var array{query: array<string, mixed>, post: array<string, mixed>, cookies: array<string, mixed>, method: string, ip: string}|null */
    private ?array $request = null;

    /** @var array<string, string> Extra headers (cookies) for the current response. */
    private array $responseHeaders = [];

    public function __construct(string $dbPath)
    {
        // Creates the database on first use and upgrades older schemas, so the
        // dashboard works before any telemetry has been written.
        $this->storage = new SQLiteStorage($dbPath);
        $this->queries = new QueryEngine($dbPath);
    }

    /**
     * URL the live refresh polls for JSON snapshots. Relative URLs resolve
     * against the dashboard page; the current query string is appended.
     */
    public function withDataEndpoint(string $url): self
    {
        $this->dataEndpoint = $url;

        return $this;
    }

    /** Repetitions of one query pattern inside a trace that flag an N+1. */
    public function withNPlusOneThreshold(int $threshold): self
    {
        $this->nPlusOneThreshold = max(2, $threshold);

        return $this;
    }

    /**
     * Form field the host framework uses for CSRF protection (e.g. Laravel's
     * `_token`). Without it the dashboard protects its forms with its own
     * double-submit cookie.
     */
    public function withFormToken(string $field, string $value): self
    {
        $this->formTokenField = $field;
        $this->formTokenValue = $value;

        return $this;
    }

    /**
     * Supplies the current request explicitly. Frameworks should call this:
     * long-running runtimes (Octane, RoadRunner) and test clients don't fill
     * PHP's superglobals, which are used otherwise.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     */
    public function withRequest(array $query, array $post, array $cookies, string $method, string $ip): self
    {
        $this->request = [
            'query' => $query,
            'post' => $post,
            'cookies' => $cookies,
            'method' => strtoupper($method),
            'ip' => $ip,
        ];

        return $this;
    }

    public function authWithBasic(string $username, string $password): self
    {
        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('Dashboard Basic Auth requires a username and password.');
        }

        $this->basicAuth = [
            'username' => hash('sha256', $username),
            'password' => hash('sha256', $password),
        ];
        $this->authorizationConfigured = true;

        return $this;
    }

    /** @param list<string> $ips */
    public function authWithIp(array $ips): self
    {
        $this->allowedIps = array_values(array_unique(array_filter(
            $ips,
            static fn (mixed $ip): bool => is_string($ip) && $ip !== ''
        )));
        $this->authorizationConfigured = true;

        return $this;
    }

    public function authorize(Closure $callback): self
    {
        $this->authorizationCallback = $callback;
        $this->authorizationConfigured = true;

        return $this;
    }

    public function render(): void
    {
        $this->emit($this->handle());
    }

    public function renderJson(): void
    {
        $this->emit($this->handleJson());
    }

    /**
     * Builds the dashboard response (page, asset, or form action) without
     * emitting it.
     *
     * Framework integrations (e.g. Laravel) must use this method so the HTTP
     * status and headers are returned explicitly instead of relying on the
     * global {@see http_response_code()} side channel.
     */
    public function handle(): DashboardResponse
    {
        $denialStatus = $this->getDenialStatus();
        if ($denialStatus !== null) {
            return $this->accessDeniedResponse($denialStatus);
        }

        $asset = $this->input('query')['asset'] ?? null;
        if (is_string($asset)) {
            return $this->assetResponse($asset);
        }

        if ($this->input('method') === 'POST') {
            return $this->actionResponse();
        }

        $traceId = $this->input('query')['trace'] ?? null;
        if (is_string($traceId) && $traceId !== '') {
            $trace = $this->queries->getTrace($traceId);

            return $this->page('trace', $trace === null ? 'Trace not found' : 'Trace', [
                'trace' => $trace,
                'waterfall' => $trace === null ? null : TraceWaterfall::build($trace),
            ], $trace === null ? 404 : 200);
        }

        $view = $this->input('query')['view'] ?? 'overview';
        $view = is_string($view) ? $view : 'overview';

        return match ($view) {
            'requests' => $this->page('requests', 'Requests', $this->requestsData()),
            'errors' => $this->page('errors', 'Errors', $this->errorsData()),
            'error' => $this->errorDetailPage(),
            'queries' => $this->page('queries', 'Queries', $this->queriesData()),
            'jobs' => $this->page('jobs', 'Jobs', $this->jobsData()),
            'outbound' => $this->page('outbound', 'Outbound HTTP', $this->outboundData()),
            'logs' => $this->page('logs', 'Logs', $this->logsData()),
            'settings' => $this->page('settings', 'Storage & settings', $this->settingsData()),
            default => $this->page('overview', 'Overview', $this->overviewData()),
        };
    }

    /**
     * Builds the live overview JSON snapshot without emitting it.
     */
    public function handleJson(): DashboardResponse
    {
        $denialStatus = $this->getDenialStatus();

        $headers = [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ];

        if ($denialStatus !== null) {
            if ($denialStatus === 401) {
                $headers['WWW-Authenticate'] = 'Basic realm="PulsePHP Dashboard", charset="UTF-8"';
            }

            return new DashboardResponse(
                $denialStatus,
                json_encode(['error' => 'Access denied'], JSON_THROW_ON_ERROR),
                $headers
            );
        }

        return new DashboardResponse(
            200,
            json_encode($this->overviewData()['live'], self::JSON_FLAGS),
            $headers
        );
    }

    /** @return array<string, mixed> */
    private function scope(): array
    {
        $period = $this->input('query')['period'] ?? null;
        $period = is_string($period) && isset(self::PERIODS[$period]) ? $period : '1h';
        $services = $this->queries->getAvailableServices();
        $service = $this->selectedOption($this->input('query')['service'] ?? null, $services);
        $routes = $this->queries->getAvailableRoutes($service);
        $route = $this->selectedOption($this->input('query')['route'] ?? null, $routes);
        [$from, $to] = $this->periodBounds($period);

        return [
            'period' => $period,
            'periodLabel' => self::PERIODS[$period],
            'periods' => self::PERIODS,
            'services' => $services,
            'service' => $service,
            'routes' => $routes,
            'route' => $route,
            'from' => $from,
            'to' => $to,
        ];
    }

    /** @return array<string, mixed> */
    private function overviewData(): array
    {
        $scope = $this->scope();
        ['period' => $period, 'service' => $service, 'route' => $route, 'from' => $from, 'to' => $to] = $scope;

        $current = $this->queries->getWindowMetrics($from, $to, $service, $route);
        $previousFrom = (new DateTimeImmutable($from, new DateTimeZone('UTC')))
            ->modify('-' . $this->periodSeconds($period) . ' seconds')
            ->format('Y-m-d H:i:s');
        $previous = $this->queries->getWindowMetrics($previousFrom, $from, $service, $route);

        $stats = $this->queries->getSummaryStats($period, $service, $route);
        $requestTimeline = $this->queries->getRequestTimeline($period, $service, $route);
        $exceptionTimeline = $this->queries->getExceptionTimeline($period, $service, $route);
        $deploys = $this->queries->getDeploys($period, $service);
        $labels = array_values(array_unique(array_merge(
            array_column($requestTimeline, 'minute'),
            array_column($exceptionTimeline, 'minute')
        )));
        sort($labels);
        $requestsByBucket = array_column($requestTimeline, 'requests', 'minute');
        $errorsByBucket = array_column($exceptionTimeline, 'exceptions', 'minute');

        $routeCards = [];
        foreach ($this->queries->getMetricsByRoute($from, $to, $service) as $metric) {
            if ($route !== null && $metric['route'] !== $route) {
                continue;
            }
            $metric['health'] = $this->routeHealth($metric);
            $metric['chart_id'] = substr(
                hash('sha256', $metric['service'] . "\0" . $metric['route'] . "\0" . $metric['method']),
                0,
                16
            );
            $routeCards[] = $metric;
        }
        $severity = ['critical' => 0, 'warning' => 1, 'healthy' => 2];
        usort($routeCards, static fn (array $a, array $b): int => [$severity[$a['health']['key']], -$a['total_requests']]
            <=> [$severity[$b['health']['key']], -$b['total_requests']]);

        $exceptionGroups = $this->queries->getExceptionGroups(100, $period, $service, $route);
        $openGroups = array_values(array_filter(
            $exceptionGroups,
            static fn (array $group): bool => in_array($group['status'], ['open', 'regressed'], true)
        ));

        $live = [
            'stats' => $stats,
            'vitals' => $this->vitals($current, $previous, $period),
            'chartData' => [
                'labels' => $labels,
                'requests' => array_map(static fn (string $label): int => (int) ($requestsByBucket[$label] ?? 0), $labels),
                'exceptions' => array_map(static fn (string $label): int => (int) ($errorsByBucket[$label] ?? 0), $labels),
                'deploys' => $this->deployMarkers($deploys, $period),
            ],
            'routeCards' => array_slice($routeCards, 0, 12),
            'exceptions' => $this->queries->getLatestExceptions(20, $period, $service, $route),
            'exceptionGroups' => array_slice($openGroups, 0, 8),
            'generatedAt' => gmdate('c'),
        ];

        return [
            'live' => $live,
            'deploys' => $deploys,
            'nPlusOne' => $this->queries->getNPlusOneQueries($this->nPlusOneThreshold, 5, $period, $service, $route),
        ];
    }

    /** @return array<string, mixed> */
    private function requestsData(): array
    {
        $scope = $this->scope();
        $filters = [
            'status' => $this->stringParam('status', ['2xx', '3xx', '4xx', '5xx']),
            'method' => $this->stringParam('method', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']),
            'min_ms' => is_numeric($this->input('query')['min_ms'] ?? null) ? (float) $this->input('query')['min_ms'] : null,
            'search' => is_string($this->input('query')['q'] ?? null) ? substr(trim($this->input('query')['q']), 0, 200) : null,
        ];
        $page = max(1, (int) ($this->input('query')['page'] ?? 1));
        $result = $this->queries->searchRequests($filters + [
            'period' => $scope['period'],
            'service' => $scope['service'],
            'route' => $scope['route'],
        ], $page, 50);

        return [
            'filters' => $filters,
            'page' => $page,
            'perPage' => 50,
            'total' => $result['total'],
            'rows' => $result['rows'],
        ];
    }

    /** @return array<string, mixed> */
    private function errorsData(): array
    {
        $scope = $this->scope();
        $status = $this->stringParam('status', ['open', 'resolved', 'ignored', 'all']) ?? 'open';

        return [
            'status' => $status,
            'groups' => $this->queries->getExceptionGroups(
                100,
                $scope['period'],
                $scope['service'],
                $scope['route'],
                $status === 'all' ? null : $status
            ),
        ];
    }

    private function errorDetailPage(): DashboardResponse
    {
        $fingerprint = $this->input('query')['id'] ?? '';
        $detail = is_string($fingerprint) && $fingerprint !== '' && strlen($fingerprint) <= 512
            ? $this->queries->getExceptionGroup($fingerprint, $this->scope()['period'])
            : null;

        return $this->page(
            'error',
            $detail === null ? 'Error not found' : $detail['group']['class'],
            ['detail' => $detail],
            $detail === null ? 404 : 200
        );
    }

    /** @return array<string, mixed> */
    private function queriesData(): array
    {
        $scope = $this->scope();

        return [
            'slowQueries' => $this->queries->getSlowestQueries(100, $scope['period'], $scope['service'], $scope['route']),
            'nPlusOne' => $this->queries->getNPlusOneQueries(
                $this->nPlusOneThreshold,
                50,
                $scope['period'],
                $scope['service'],
                $scope['route']
            ),
            'threshold' => $this->nPlusOneThreshold,
        ];
    }

    /** @return array<string, mixed> */
    private function jobsData(): array
    {
        $scope = $this->scope();

        return ['jobs' => $this->queries->getJobs($scope['period'], $scope['service'])];
    }

    /** @return array<string, mixed> */
    private function outboundData(): array
    {
        $scope = $this->scope();

        return [
            'hosts' => $this->queries->getOutboundHosts($scope['period'], $scope['service'], $scope['route']),
            'calls' => $this->queries->getOutboundRequests(100, $scope['period'], $scope['service'], $scope['route']),
        ];
    }

    /** @return array<string, mixed> */
    private function logsData(): array
    {
        $scope = $this->scope();
        $level = $this->stringParam('level', ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']);
        $search = is_string($this->input('query')['q'] ?? null) ? substr(trim($this->input('query')['q']), 0, 200) : null;

        return [
            'level' => $level,
            'search' => $search,
            'logs' => $this->queries->getLogs(200, $scope['period'], $scope['service'], $scope['route'], $level, $search),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsData(): array
    {
        return [
            'storage' => $this->queries->getStorageInfo(),
            'deploys' => $this->queries->getDeploys('7d'),
            'nPlusOneThreshold' => $this->nPlusOneThreshold,
        ];
    }

    /**
     * Renders a screen inside the shared layout.
     *
     * @param array<string, mixed> $data
     */
    private function page(string $screen, string $title, array $data, int $status = 200): DashboardResponse
    {
        $scope = $this->scope();
        $page = [
            'screen' => $screen,
            'title' => $title,
            'nav' => self::SCREENS,
            'openErrors' => count($this->queries->getExceptionGroups(100, $scope['period'], $scope['service'], $scope['route'], 'open')),
            'assetVersion' => $this->assetVersion(),
            'dataEndpoint' => $this->dataEndpoint,
            'csrf' => $this->csrfField(),
            'palette' => $this->paletteItems($scope),
        ];

        $view = new View(__DIR__ . '/resources/views', $scope);
        $html = $view->render('layout', ['page' => $page, 'data' => $data]);

        return new DashboardResponse($status, $html, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
        ] + $this->responseHeaders);
    }

    private function actionResponse(): DashboardResponse
    {
        if (!$this->validCsrf()) {
            return new DashboardResponse(419, 'The form expired. Reload the page and try again.', [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $action = $this->input('post')['action'] ?? null;
        $fingerprint = $this->input('post')['fingerprint'] ?? null;
        $statuses = ['resolve' => 'resolved', 'ignore' => 'ignored', 'reopen' => 'open'];
        if (!is_string($action) || !isset($statuses[$action])
            || !is_string($fingerprint) || $fingerprint === '' || strlen($fingerprint) > 512) {
            return new DashboardResponse(422, 'Unknown action.', [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $this->storage->setExceptionStatus($fingerprint, $statuses[$action]);

        return new DashboardResponse(303, '', [
            'Location' => '?' . http_build_query(['view' => 'error', 'id' => $fingerprint]),
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function assetResponse(string $name): DashboardResponse
    {
        if (!isset(self::ASSETS[$name])) {
            return new DashboardResponse(404, 'Not found', ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        [$file, $contentType] = self::ASSETS[$name];
        $contents = @file_get_contents(__DIR__ . '/resources/assets/' . $file);
        if ($contents === false) {
            return new DashboardResponse(404, 'Not found', ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new DashboardResponse(200, $contents, [
            'Content-Type' => $contentType,
            'Cache-Control' => ($this->input('query')['v'] ?? null) === $this->assetVersion()
                ? 'private, max-age=31536000, immutable'
                : 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assetVersion(): string
    {
        $fingerprint = '';
        foreach (['app.css', 'app.js', 'chart.umd.min.js', 'alpine.min.js'] as $file) {
            $path = __DIR__ . '/resources/assets/' . $file;
            $fingerprint .= is_file($path) ? filemtime($path) . ':' . filesize($path) . ';' : '';
        }

        return substr(hash('sha256', $fingerprint), 0, 12);
    }

    /** @return array{name: string, value: string} */
    private function csrfField(): array
    {
        if ($this->formTokenField !== null && $this->formTokenValue !== null) {
            return ['name' => $this->formTokenField, 'value' => $this->formTokenValue];
        }

        $token = $this->input('cookies')[self::CSRF_COOKIE] ?? null;
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $this->responseHeaders['Set-Cookie'] = self::CSRF_COOKIE . '=' . $token
                . '; Path=/; HttpOnly; SameSite=Strict';
        }

        return ['name' => self::CSRF_COOKIE, 'value' => $token];
    }

    private function validCsrf(): bool
    {
        // The host framework (e.g. Laravel's VerifyCsrfToken) already checked it.
        if ($this->formTokenField !== null) {
            return true;
        }

        $cookie = $this->input('cookies')[self::CSRF_COOKIE] ?? null;
        $field = $this->input('post')[self::CSRF_COOKIE] ?? null;

        return is_string($cookie) && is_string($field) && $cookie !== '' && hash_equals($cookie, $field);
    }

    /**
     * @param array{requests: int, error_rate_percent: float, p95_ms: float, exceptions: int} $current
     * @param array{requests: int, error_rate_percent: float, p95_ms: float, exceptions: int} $previous
     * @return list<array{key: string, label: string, value: float, unit: string, delta: string, trend: string}>
     */
    private function vitals(array $current, array $previous, string $period): array
    {
        $versus = 'vs ' . self::PREVIOUS_LABELS[$period];
        $relative = static function (float $now, float $before, bool $higherIsWorse) use ($versus): array {
            if ($before <= 0.0) {
                return [$now > 0 ? 'No data for the ' . substr($versus, 3) : 'No traffic yet', 'flat'];
            }
            $change = ($now - $before) / $before * 100;
            if (abs($change) < 1) {
                return ['Steady ' . $versus, 'flat'];
            }
            $worse = $higherIsWorse ? $change > 0 : $change < 0;

            return [sprintf('%s%.0f%% %s', $change > 0 ? '+' : '−', abs($change), $versus), $worse ? 'worse' : 'better'];
        };

        [$throughputDelta] = $relative((float) $current['requests'], (float) $previous['requests'], false);
        [$p95Delta, $p95Trend] = $relative($current['p95_ms'], $previous['p95_ms'], true);
        [$exceptionDelta, $exceptionTrend] = $relative((float) $current['exceptions'], (float) $previous['exceptions'], true);
        $pointChange = $current['error_rate_percent'] - $previous['error_rate_percent'];
        $errorDelta = $previous['requests'] === 0
            ? 'No data for the ' . substr($versus, 3)
            : (abs($pointChange) < 0.05 ? 'Steady ' . $versus : sprintf('%s%.1f pts %s', $pointChange > 0 ? '+' : '−', abs($pointChange), $versus));

        return [
            ['key' => 'requests', 'label' => 'Requests', 'value' => (float) $current['requests'], 'unit' => '', 'delta' => $throughputDelta, 'trend' => 'flat'],
            ['key' => 'p95', 'label' => 'p95 latency', 'value' => $current['p95_ms'], 'unit' => 'ms', 'delta' => $p95Delta, 'trend' => $p95Trend],
            ['key' => 'error_rate', 'label' => 'Server error rate', 'value' => $current['error_rate_percent'], 'unit' => '%', 'delta' => $errorDelta, 'trend' => $previous['requests'] === 0 ? 'flat' : ($pointChange >= 0.05 ? 'worse' : ($pointChange <= -0.05 ? 'better' : 'flat'))],
            ['key' => 'exceptions', 'label' => 'Exceptions', 'value' => (float) $current['exceptions'], 'unit' => '', 'delta' => $exceptionDelta, 'trend' => $exceptionTrend],
        ];
    }

    /**
     * @param list<array{version: string, service: string, created_at: string}> $deploys
     * @return list<array{bucket: string, version: string}>
     */
    private function deployMarkers(array $deploys, string $period): array
    {
        $format = match ($period) {
            '24h' => 'Y-m-d H:00:00',
            '7d' => 'Y-m-d 00:00:00',
            default => 'Y-m-d H:i:00',
        };

        return array_map(static fn (array $deploy): array => [
            'bucket' => (new DateTimeImmutable((string) $deploy['created_at'], new DateTimeZone('UTC')))->format($format),
            'version' => (string) $deploy['version'],
        ], $deploys);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{label: string, kind: string, url: string}>
     */
    private function paletteItems(array $scope): array
    {
        $items = [];
        foreach (self::SCREENS as $key => $label) {
            $items[] = ['label' => $label, 'kind' => 'Screen', 'url' => '?' . http_build_query(['view' => $key, 'period' => $scope['period']])];
        }
        foreach (array_slice($scope['routes'], 0, 200) as $route) {
            $items[] = ['label' => $route, 'kind' => 'Route', 'url' => '?' . http_build_query(['view' => 'requests', 'period' => $scope['period'], 'route' => $route])];
        }
        foreach ($scope['services'] as $service) {
            $items[] = ['label' => $service, 'kind' => 'Service', 'url' => '?' . http_build_query(['period' => $scope['period'], 'service' => $service])];
        }
        foreach ($this->queries->getExceptionGroups(30, '7d') as $group) {
            $items[] = [
                'label' => ($group['class'] !== '' ? $group['class'] . ': ' : '') . mb_strimwidth($group['message'], 0, 90, '…'),
                'kind' => 'Error',
                'url' => '?' . http_build_query(['view' => 'error', 'id' => $group['fingerprint'], 'period' => $scope['period']]),
            ];
        }

        return $items;
    }

    /** @return ($part is 'method'|'ip' ? string : array<string, mixed>) */
    private function input(string $part): string|array
    {
        if ($this->request !== null) {
            return $this->request[$part];
        }

        return match ($part) {
            'query' => $_GET,
            'post' => $_POST,
            'cookies' => $_COOKIE,
            'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        };
    }

    /** @param list<string> $allowed */
    private function stringParam(string $name, array $allowed): ?string
    {
        $value = $this->input('query')[$name] ?? null;

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** @param list<string> $options */
    private function selectedOption(mixed $value, array $options): ?string
    {
        return is_string($value) && in_array($value, $options, true) ? $value : null;
    }

    private function periodSeconds(string $period): int
    {
        return match ($period) {
            '15m' => 15 * 60,
            '24h' => 24 * 3600,
            '7d' => 7 * 24 * 3600,
            default => 3600,
        };
    }

    /** @return array{string, string} */
    private function periodBounds(string $period): array
    {
        $to = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $from = $to->modify('-' . $this->periodSeconds($period) . ' seconds');

        // `to` is exclusive in window queries; include the current second.
        return [$from->format('Y-m-d H:i:s'), $to->modify('+1 second')->format('Y-m-d H:i:s')];
    }

    /** @param array{avg_duration_ms: float, error_rate_percent: float, unhandled_exceptions: int} $metric
     * @return array{key: string, label: string, color: string}
     */
    private function routeHealth(array $metric): array
    {
        if ($metric['unhandled_exceptions'] > 0
            || $metric['avg_duration_ms'] > 1000
            || $metric['error_rate_percent'] > 5) {
            return ['key' => 'critical', 'label' => 'Critical', 'color' => 'crit'];
        }

        if ($metric['avg_duration_ms'] >= 300 || $metric['error_rate_percent'] >= 1) {
            return ['key' => 'warning', 'label' => 'Slow', 'color' => 'warn'];
        }

        return ['key' => 'healthy', 'label' => 'Healthy', 'color' => 'ok'];
    }

    private function getDenialStatus(): ?int
    {
        if (!$this->authorizationConfigured) {
            return 403;
        }

        if ($this->basicAuth !== null) {
            $credentials = $this->readBasicCredentials();
            if ($credentials === null) {
                return 401;
            }

            $usernameMatches = hash_equals($this->basicAuth['username'], hash('sha256', $credentials[0]));
            $passwordMatches = hash_equals($this->basicAuth['password'], hash('sha256', $credentials[1]));
            if (!$usernameMatches || !$passwordMatches) {
                return 401;
            }
        }

        if ($this->allowedIps !== null
            && !in_array($this->input('ip'), $this->allowedIps, true)) {
            return 403;
        }

        if ($this->authorizationCallback !== null) {
            try {
                if (!(($this->authorizationCallback)())) {
                    return 403;
                }
            } catch (Throwable) {
                return 403;
            }
        }

        return null;
    }

    /** @return array{string, string}|null */
    private function readBasicCredentials(): ?array
    {
        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            return [(string) $_SERVER['PHP_AUTH_USER'], (string) $_SERVER['PHP_AUTH_PW']];
        }

        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');
        if (!preg_match('/^Basic\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        return explode(':', $decoded, 2);
    }

    private function accessDeniedResponse(int $status): DashboardResponse
    {
        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ];
        if ($status === 401) {
            $headers['WWW-Authenticate'] = 'Basic realm="PulsePHP Dashboard", charset="UTF-8"';
        }

        return new DashboardResponse(
            $status,
            '<!doctype html><html lang="en"><meta charset="utf-8">'
            . '<title>Access denied</title><body><h1>Access denied</h1></body></html>',
            $headers
        );
    }

    private function emit(DashboardResponse $response): void
    {
        foreach ($response->headers() as $name => $value) {
            if (!headers_sent()) {
                header($name . ': ' . $value);
            }
        }

        http_response_code($response->status);

        echo $response->body;
    }
}
