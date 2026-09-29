<?php

declare(strict_types=1);

namespace PulsePHP;

use LogicException;
use PulsePHP\Collectors\ExceptionCollector;
use PulsePHP\Collectors\RequestCollector;
use PulsePHP\Contracts\StorageInterface;
use PulsePHP\Storage\CatalogEngine;
use PulsePHP\Storage\SQLiteStorage;
use Throwable;

final class Pulse
{
    private static ?self $instance = null;

    /** @var array<string, list<array<string, mixed>>> */
    private array $buffer = [
        'metrics' => [],
        'spans' => [],
        'exceptions' => [],
        'requests' => [],
        'queries' => [],
        'outbound_requests' => [],
    ];

    /** @var array<string, array{started_at: int, memory: int, service: string, route: string}> */
    private array $timers = [];

    private ?RequestCollector $requestCollector;
    private string $service = 'default';
    private string $route = '/';
    private string $method = '';

    private function __construct(
        private StorageInterface $storage,
        bool $registerStandaloneCollectors,
        string $serviceName
    ) {
        $this->setContext(
            $serviceName,
            $this->routeFromRequest(),
            (string) ($_SERVER['REQUEST_METHOD'] ?? '')
        );

        if ($registerStandaloneCollectors) {
            (new ExceptionCollector($this))->register();
            $this->requestCollector = new RequestCollector($this);
        } else {
            $this->requestCollector = null;
        }

        register_shutdown_function([$this, 'flush']);
    }

    public static function init(
        string $dbPath,
        bool $registerStandaloneCollectors = true,
        ?string $serviceName = null
    ): self {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $serviceName ??= (string) (getenv('PULSE_SERVICE_NAME') ?: getenv('APP_NAME') ?: 'default');
        self::$instance = new self(
            new SQLiteStorage($dbPath),
            $registerStandaloneCollectors,
            $serviceName
        );

        return self::$instance;
    }

    public function setContext(string $service, ?string $route = null, ?string $method = null): void
    {
        $this->service = trim($service) !== '' ? trim($service) : 'default';
        if ($route !== null) {
            $this->route = trim($route) !== '' ? trim($route) : '/';
        }
        if ($method !== null) {
            $this->method = strtoupper(trim($method));
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new LogicException('Pulse has not been initialized. Call Pulse::init() first.');
        }

        return self::$instance;
    }

    public function recordEvent(string $name): void
    {
        $this->recordMetric($name, 1.0, ['type' => 'event']);
    }

    public function recordMetric(string $name, float $value, array $tags = []): void
    {
        $this->buffer['metrics'][] = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'service' => $this->service,
            'route' => $this->route,
        ];
    }

    public function startTimer(string $key): void
    {
        $this->timers[$key] = [
            'started_at' => hrtime(true),
            'memory' => memory_get_usage(true),
            'service' => $this->service,
            'route' => $this->route,
        ];
    }

    public function endTimer(string $key): void
    {
        if (!isset($this->timers[$key])) {
            return;
        }

        $timer = $this->timers[$key];
        unset($this->timers[$key]);

        $this->buffer['spans'][] = [
            'name' => $key,
            'duration_ms' => (hrtime(true) - $timer['started_at']) / 1_000_000,
            'memory_bytes' => max(0, memory_get_usage(true) - $timer['memory']),
            'service' => $timer['service'],
            'route' => $timer['route'],
        ];
    }

    public function recordException(Throwable $exception, bool $unhandled = false): void
    {
        $this->buffer['exceptions'][] = [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'method' => $this->method,
            'unhandled' => $unhandled,
            'service' => $this->service,
            'route' => $this->route,
        ];
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
        $this->buffer['requests'][] = [
            'url' => $url,
            'method' => $method,
            'status_code' => $status,
            'duration_ms' => $duration,
            'memory_bytes' => $memory,
            'ip' => $ip,
            'service' => $service ?? $this->service,
            'route' => $route ?? $this->route,
        ];
    }

    public function recordQuery(string $sql, float $durationMs): void
    {
        $normalizedSql = CatalogEngine::normalizeSql($sql);
        $this->buffer['queries'][] = [
            'hash' => CatalogEngine::hashSql($normalizedSql),
            'normalized_sql' => $normalizedSql,
            'duration_ms' => $durationMs,
            'service' => $this->service,
            'route' => $this->route,
        ];
    }

    public function recordOutboundRequest(
        string $url,
        string $method,
        int $statusCode,
        float $durationMs
    ): void {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? 'unknown')) : 'unknown';
        $safeUrl = $this->sanitizeOutboundUrl($parts);

        $this->buffer['spans'][] = [
            'name' => 'http.outbound:' . $host,
            'duration_ms' => max(0.0, $durationMs),
            'memory_bytes' => 0,
            'service' => $this->service,
            'route' => $this->route,
        ];
        $this->buffer['outbound_requests'][] = [
            'service' => $this->service,
            'route' => $this->route,
            'method' => strtoupper($method),
            'status_code' => $statusCode,
            'url' => $safeUrl,
            'duration_ms' => max(0.0, $durationMs),
        ];
    }

    public function flush(): void
    {
        $this->requestCollector?->collect();

        if ($this->buffer === [
            'metrics' => [],
            'spans' => [],
            'exceptions' => [],
            'requests' => [],
            'queries' => [],
            'outbound_requests' => [],
        ]) {
            return;
        }

        $buffer = $this->buffer;
        $this->buffer = [
            'metrics' => [],
            'spans' => [],
            'exceptions' => [],
            'requests' => [],
            'queries' => [],
            'outbound_requests' => [],
        ];

        try {
            $this->storage->writeBatch($buffer);
        } catch (Throwable $exception) {
            $this->buffer = array_merge_recursive($buffer, $this->buffer);
            throw $exception;
        }
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
