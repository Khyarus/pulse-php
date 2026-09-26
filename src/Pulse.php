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
    ];

    /** @var array<string, array{started_at: int, memory: int}> */
    private array $timers = [];

    private RequestCollector $requestCollector;

    private function __construct(private StorageInterface $storage)
    {
        (new ExceptionCollector($this))->register();
        $this->requestCollector = new RequestCollector($this);
        register_shutdown_function([$this, 'flush']);
    }

    public static function init(string $dbPath): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new self(new SQLiteStorage($dbPath));

        return self::$instance;
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
        ];
    }

    public function startTimer(string $key): void
    {
        $this->timers[$key] = [
            'started_at' => hrtime(true),
            'memory' => memory_get_usage(true),
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
        ];
    }

    public function recordException(Throwable $exception): void
    {
        $this->buffer['exceptions'][] = [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];
    }

    public function recordRequest(
        string $url,
        string $method,
        int $status,
        float $duration,
        int $memory,
        ?string $ip = null
    ): void {
        $this->buffer['requests'][] = [
            'url' => $url,
            'method' => $method,
            'status_code' => $status,
            'duration_ms' => $duration,
            'memory_bytes' => $memory,
            'ip' => $ip,
        ];
    }

    public function recordQuery(string $sql, float $durationMs): void
    {
        $normalizedSql = CatalogEngine::normalizeSql($sql);
        $this->buffer['queries'][] = [
            'hash' => CatalogEngine::hashSql($normalizedSql),
            'normalized_sql' => $normalizedSql,
            'duration_ms' => $durationMs,
        ];
    }

    public function flush(): void
    {
        $this->requestCollector->collect();

        if ($this->buffer === [
            'metrics' => [],
            'spans' => [],
            'exceptions' => [],
            'requests' => [],
            'queries' => [],
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
        ];

        try {
            $this->storage->writeBatch($buffer);
        } catch (Throwable $exception) {
            $this->buffer = array_merge_recursive($buffer, $this->buffer);
            throw $exception;
        }
    }
}