<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use PulsePHP\Pulse;
use PulsePHP\Storage\SQLiteStorage;
use ReflectionProperty;
use RuntimeException;

final class PulseTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
    }

    public function test_it_buffers_events_metrics_and_timer_spans_until_flush(): void
    {
        $pulse = Pulse::init(':memory:', false);
        $storageProperty = new ReflectionProperty(Pulse::class, 'storage');
        $storage = $storageProperty->getValue($pulse);
        $pdo = (new ReflectionProperty(SQLiteStorage::class, 'pdo'))->getValue($storage);

        self::assertInstanceOf(PDO::class, $pdo);

        $pulse->recordEvent('user.signed_in');
        $pulse->recordMetric('queue.depth', 3.5, ['queue' => 'emails']);
        $pulse->startTimer('email.send');
        $pulse->endTimer('email.send');
        $pulse->endTimer('missing.timer');

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pulse_metrics')->fetchColumn());
        $pulse->flush();

        $metrics = $pdo->query('SELECT name, value, tags FROM pulse_metrics ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $metrics);
        self::assertSame('user.signed_in', $metrics[0]['name']);
        self::assertSame(1.0, (float) $metrics[0]['value']);
        self::assertSame(['type' => 'event'], json_decode($metrics[0]['tags'], true));
        self::assertSame('queue.depth', $metrics[1]['name']);
        self::assertSame(['queue' => 'emails'], json_decode($metrics[1]['tags'], true));

        $span = $pdo->query('SELECT name, duration_ms, memory_bytes FROM pulse_spans')
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('email.send', $span['name']);
        self::assertGreaterThanOrEqual(0, (float) $span['duration_ms']);
        self::assertGreaterThanOrEqual(0, (int) $span['memory_bytes']);
    }

    public function test_it_attaches_service_and_route_context_to_telemetry(): void
    {
        $pulse = Pulse::init(':memory:', false, 'billing-service');
        $pulse->setContext('billing-service', 'orders.create', 'POST');
        $pulse->recordMetric('orders.created', 1.0);
        $pulse->recordQuery('SELECT 1', 0.5);
        $pulse->recordRequest('/orders', 'POST', 201, 2.0, 128);
        $pulse->recordException(new RuntimeException('sample failure'), true);
        $pulse->startTimer('order.persist');
        $pulse->endTimer('order.persist');
        $pulse->recordOutboundRequest('https://user:secret@example.test/pay?token=hidden', 'post', 201, 9.0);
        $pulse->flush();

        $storage = (new ReflectionProperty(Pulse::class, 'storage'))->getValue($pulse);
        $pdo = (new ReflectionProperty(SQLiteStorage::class, 'pdo'))->getValue($storage);

        foreach ([
            'pulse_metrics',
            'pulse_queries',
            'pulse_requests',
            'pulse_exceptions',
            'pulse_spans',
            'pulse_outbound_requests',
        ] as $table) {
            $record = $pdo->query("SELECT service, route FROM {$table} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            self::assertSame('billing-service', $record['service'], $table);
            self::assertSame('orders.create', $record['route'], $table);
        }

        $exception = $pdo->query('SELECT method, unhandled FROM pulse_exceptions')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('POST', $exception['method']);
        self::assertSame(1, (int) $exception['unhandled']);

        $outbound = $pdo->query('SELECT method, status_code, url FROM pulse_outbound_requests')
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame('POST', $outbound['method']);
        self::assertSame(201, (int) $outbound['status_code']);
        self::assertSame('https://example.test/pay', $outbound['url']);
    }
}