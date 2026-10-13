<?php

declare(strict_types=1);

namespace PulsePHP;

use Closure;
use InvalidArgumentException;
use LogicException;
use PulsePHP\Collectors\ExceptionCollector;
use PulsePHP\Collectors\RequestCollector;
use PulsePHP\Contracts\StorageInterface;
use PulsePHP\Storage\CatalogEngine;
use PulsePHP\Storage\SQLiteStorage;
use Throwable;

final class Pulse
{
    private const EMPTY_BUFFER = [
        'metrics' => [],
        'spans' => [],
        'exceptions' => [],
        'requests' => [],
        'queries' => [],
        'outbound_requests' => [],
    ];

    private const DEFAULT_OPTIONS = [
        // Fraction (0.0–1.0) of requests whose request/query/span/outbound
        // telemetry is recorded. Exceptions and explicit metrics are always kept.
        'sample_rate' => 1.0,
        // Request paths that are never recorded. `*` matches any characters.
        'ignore_paths' => [],
        // Buffered records that trigger an early write. Keeps long-running
        // workers (queues, Octane) from growing memory without bound.
        'max_buffer_size' => 1000,
        // Days of telemetry to keep. 0 disables automatic pruning.
        'retention_days' => 7,
        // [chance, out of]: probability that a flush also prunes old rows.
        'prune_lottery' => [1, 200],
    ];

    private static ?self $instance = null;

    /** @var array<string, list<array<string, mixed>>> */
    private array $buffer = self::EMPTY_BUFFER;
    private int $bufferedRecords = 0;

    /** @var array<string, array{started_at: int, memory: int, service: string, route: string, trace_id: string}> */
    private array $timers = [];

    /** @var array{sample_rate: float, ignore_paths: list<string>, max_buffer_size: int, retention_days: int, prune_lottery: array{int, int}} */
    private array $options = self::DEFAULT_OPTIONS;

    private ?RequestCollector $requestCollector;
    private ?Closure $errorReporter = null;
    private string $service = 'default';
    private string $route = '/';
    private string $method = '';
    private string $traceId = '';
    private bool $recording = true;

    /** @param array<string, mixed> $options */
    private function __construct(
        private StorageInterface $storage,
        bool $registerStandaloneCollectors,
        string $serviceName,
        array $options
    ) {
        $this->configure($options);
        $this->service = $this->cleanService($serviceName);
        $this->beginTrace(
            $this->routeFromRequest(),
            (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            $this->routeFromRequest()
        );

        if ($registerStandaloneCollectors) {
            (new ExceptionCollector($this))->register();
            $this->requestCollector = new RequestCollector($this);
        } else {
            $this->requestCollector = null;
        }

        register_shutdown_function([$this, 'flush']);
    }

    /**
     * @param array{
     *     sample_rate?: float,
     *     ignore_paths?: list<string>,
     *     max_buffer_size?: int,
     *     retention_days?: int,
     *     prune_lottery?: array{int, int}
     * } $options
     */
    public static function init(
        string $dbPath,
        bool $registerStandaloneCollectors = true,
        ?string $serviceName = null,
        array $options = []
    ): self {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $serviceName ??= (string) (getenv('PULSE_SERVICE_NAME') ?: getenv('APP_NAME') ?: 'default');
        self::$instance = new self(
            new SQLiteStorage($dbPath),
            $registerStandaloneCollectors,
            $serviceName,
            $options
        );

        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new LogicException('Pulse has not been initialized. Call Pulse::init() first.');
        }

        return self::$instance;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    /** @param array<string, mixed> $options */
    public function configure(array $options): self
    {
        $unknown = array_diff(array_keys($options), array_keys(self::DEFAULT_OPTIONS));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown Pulse option(s): ' . implode(', ', $unknown));
        }

        $options += $this->options;
        $lottery = array_values((array) $options['prune_lottery']);

        $this->options = [
            'sample_rate' => max(0.0, min(1.0, (float) $options['sample_rate'])),
            'ignore_paths' => array_values(array_filter(
                array_map('strval', (array) $options['ignore_paths']),
                static fn (string $path): bool => $path !== ''
            )),
            'max_buffer_size' => max(1, (int) $options['max_buffer_size']),
            'retention_days' => max(0, (int) $options['retention_days']),
            'prune_lottery' => [max(0, (int) ($lottery[0] ?? 0)), max(1, (int) ($lottery[1] ?? 1))],
        ];

        return $this;
    }

    /**
     * Receives storage failures. Telemetry must never break the host
     * application, so failed writes are reported here and then dropped.
     * Defaults to error_log().
     */
    public function reportErrorsUsing(?Closure $reporter): self
    {
        $this->errorReporter = $reporter;

        return $this;
    }

    /**
     * Starts a new unit of work (HTTP request, queued job, command) and
     * returns its trace id. Telemetry recorded until the next call is
     * correlated through that id.
     *
     * $path is matched against `ignore_paths`; the sampling decision is made
     * here once per unit of work.
     */
    public function beginTrace(
        string $route,
        string $method = '',
        ?string $path = null,
        ?string $service = null
    ): string {
        if ($service !== null) {
            $this->service = $this->cleanService($service);
        }
        $this->route = trim($route) !== '' ? trim($route) : '/';
        $this->method = strtoupper(trim($method));
        $this->traceId = bin2hex(random_bytes(8));
        $this->recording = !$this->isIgnoredPath($path ?? $this->route) && $this->sampled();

        return $this->traceId;
    }

    public function setContext(string $service, ?string $route = null, ?string $method = null): void
    {
        $this->service = $this->cleanService($service);
        if ($route !== null) {
            $this->route = trim($route) !== '' ? trim($route) : '/';
        }
        if ($method !== null) {
            $this->method = strtoupper(trim($method));
        }
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    /** Whether request-scoped telemetry is being kept for the current trace. */
    public function isRecording(): bool
    {
        return $this->recording;
    }

    public function isIgnoredPath(string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach ($this->options['ignore_paths'] as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote('/' . ltrim($pattern, '/'), '#')) . '$#';
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    public function recordEvent(string $name): void
    {
        $this->recordMetric($name, 1.0, ['type' => 'event']);
    }

    public function recordMetric(string $name, float $value, array $tags = []): void
    {
        $this->push('metrics', [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'service' => $this->service,
            'route' => $this->route,
        ]);
    }

    public function startTimer(string $key): void
    {
        $this->timers[$key] = [
            'started_at' => hrtime(true),
            'memory' => memory_get_usage(true),
            'service' => $this->service,
            'route' => $this->route,
            'trace_id' => $this->traceId,
        ];
    }

    public function endTimer(string $key): void
    {
        if (!isset($this->timers[$key])) {
            return;
        }

        $timer = $this->timers[$key];
        unset($this->timers[$key]);

        if (!$this->recording) {
            return;
        }

        $this->push('spans', [
            'name' => $key,
            'duration_ms' => (hrtime(true) - $timer['started_at']) / 1_000_000,
            'memory_bytes' => max(0, memory_get_usage(true) - $timer['memory']),
            'service' => $timer['service'],
            'route' => $timer['route'],
            'trace_id' => $timer['trace_id'],
        ]);
    }

    public function recordException(Throwable $exception, bool $unhandled = false): void
    {
        $class = $exception::class;

        $this->push('exceptions', [
            'class' => $class,
            'fingerprint' => sha1($class . '|' . $exception->getFile() . '|' . $exception->getLine()),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'method' => $this->method,
            'unhandled' => $unhandled,
            'service' => $this->service,
            'route' => $this->route,
            'trace_id' => $this->traceId,
        ]);
    }

    public function recordRequest(
        string $url,
        string $method,
        int $status,
        float $duration,
        int $memory,
        ?string $ip = null,
        ?string $service = null,
        ?string $route = null
    ): void {
        if (!$this->recording) {
            return;
        }

        $this->push('requests', [
            'url' => $url,
            'method' => $method,
            'status_code' => $status,
            'duration_ms' => $duration,
            'memory_bytes' => $memory,
            'ip' => $ip,
            'service' => $service ?? $this->service,
            'route' => $route ?? $this->route,
            'trace_id' => $this->traceId,
        ]);
    }

    public function recordQuery(string $sql, float $durationMs): void
    {
        if (!$this->recording) {
            return;
        }

        $normalizedSql = CatalogEngine::normalizeSql($sql);
        $this->push('queries', [
            'hash' => CatalogEngine::hashSql($normalizedSql),
            'normalized_sql' => $normalizedSql,
            'duration_ms' => $durationMs,
            'service' => $this->service,
            'route' => $this->route,
            'trace_id' => $this->traceId,
        ]);
    }

    public function recordOutboundRequest(
        string $url,
        string $method,
        int $statusCode,
        float $durationMs
    ): void {
        if (!$this->recording) {
            return;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? 'unknown')) : 'unknown';
        $safeUrl = $this->sanitizeOutboundUrl($parts);

        $this->push('spans', [
            'name' => 'http.outbound:' . $host,
            'duration_ms' => max(0.0, $durationMs),
            'memory_bytes' => 0,
            'service' => $this->service,
            'route' => $this->route,
            'trace_id' => $this->traceId,
        ]);
        $this->push('outbound_requests', [
            'service' => $this->service,
            'route' => $this->route,
            'method' => strtoupper($method),
            'status_code' => $statusCode,
            'url' => $safeUrl,
            'duration_ms' => max(0.0, $durationMs),
            'trace_id' => $this->traceId,
        ]);
    }

    /**
     * Collects the standalone request (when enabled) and writes everything
     * buffered. Never throws: storage failures go to the error reporter.
     */
    public function flush(): void
    {
        $this->requestCollector?->collect();
        $this->writeBuffer();
    }

    /** Writes buffered telemetry without finishing the current request. */
    public function writeBuffer(): void
    {
        if ($this->bufferedRecords === 0) {
            return;
        }

        $buffer = $this->buffer;
        $this->buffer = self::EMPTY_BUFFER;
        $this->bufferedRecords = 0;

        try {
            $this->storage->writeBatch($buffer);
        } catch (Throwable $exception) {
            $this->report('Unable to write telemetry; the batch was dropped.', $exception);

            return;
        }

        $this->maybePrune();
    }

    /** @return array<string, int> Deleted rows per table. */
    public function prune(?int $retentionDays = null, bool $vacuum = false): array
    {
        return $this->storage->prune($retentionDays ?? max(1, $this->options['retention_days']), $vacuum);
    }

    /** @param array<string, mixed> $record */
    private function push(string $type, array $record): void
    {
        $this->buffer[$type][] = $record;

        if (++$this->bufferedRecords >= $this->options['max_buffer_size']) {
            $this->writeBuffer();
        }
    }

    private function maybePrune(): void
    {
        [$chance, $outOf] = $this->options['prune_lottery'];
        if ($this->options['retention_days'] < 1 || $chance < 1 || random_int(1, $outOf) > $chance) {
            return;
        }

        try {
            $this->storage->prune($this->options['retention_days']);
        } catch (Throwable $exception) {
            $this->report('Unable to prune old telemetry.', $exception);
        }
    }

    private function sampled(): bool
    {
        $rate = $this->options['sample_rate'];
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return random_int(1, 1_000_000) <= (int) round($rate * 1_000_000);
    }

    private function report(string $message, Throwable $exception): void
    {
        try {
            if ($this->errorReporter !== null) {
                ($this->errorReporter)($message, $exception);

                return;
            }

            error_log(sprintf('[PulsePHP] %s %s: %s', $message, $exception::class, $exception->getMessage()));
        } catch (Throwable) {
            // Reporting must never interrupt the host application either.
        }
    }

    private function cleanService(string $service): string
    {
        return trim($service) !== '' ? trim($service) : 'default';
    }

    private function routeFromRequest(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /** @param array<string, mixed>|false $parts */
    private function sanitizeOutboundUrl(array|false $parts): string
    {
        if ($parts === false || !isset($parts['host'])) {
            return '[invalid-url]';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');

        return $scheme . '://' . $host . $port . $path;
    }
}
