<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use PulsePHP\Contracts\StorageInterface;
use PulsePHP\Pulse;
use PulsePHP\Storage\SQLiteStorage;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class PulseRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
    }

    public function test_it_correlates_telemetry_of_one_trace(): void
    {
        $pulse = Pulse::init(':memory:', false);
        $traceId = $pulse->beginTrace('orders.show', 'GET', '/orders/1');
        $pulse->recordQuery('SELECT * FROM orders WHERE id = 1', 1.0);
        $pulse->recordRequest('/orders/1', 'GET', 200, 5.0, 0);
        $pulse->recordException(new RuntimeException('failed'));
        $otherTrace = $pulse->beginTrace('orders.index', 'GET', '/orders');
        $pulse->recordQuery('SELECT * FROM orders', 1.0);
        $pulse->flush();

        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $traceId);
        self::assertNotSame($traceId, $otherTrace);

        $pdo = $this->pdo($pulse);
        self::assertSame($traceId, $pdo->query('SELECT trace_id FROM pulse_requests')->fetchColumn());
        self::assertSame($traceId, $pdo->query('SELECT trace_id FROM pulse_exceptions')->fetchColumn());
        self::assertSame(
            [$traceId, $otherTrace],
            $pdo->query('SELECT trace_id FROM pulse_queries ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function test_it_stores_exception_class_and_a_stable_fingerprint(): void
    {
        $pulse = Pulse::init(':memory:', false);
        foreach ([1, 2] as $attempt) {
            $pulse->recordException($this->failure("attempt {$attempt}"));
        }
        $pulse->flush();

        $rows = $this->pdo($pulse)->query('SELECT class, fingerprint FROM pulse_exceptions')
            ->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(RuntimeException::class, $rows[0]['class']);
        self::assertSame($rows[0]['fingerprint'], $rows[1]['fingerprint']);
        self::assertSame(40, strlen($rows[0]['fingerprint']));
    }

    public function test_unsampled_traces_drop_request_telemetry_but_keep_exceptions_and_metrics(): void
    {
        $pulse = Pulse::init(':memory:', false, null, ['sample_rate' => 0.0]);
        $pulse->beginTrace('home', 'GET', '/');
        self::assertFalse($pulse->isRecording());

        $pulse->recordRequest('/', 'GET', 200, 1.0, 0);
        $pulse->recordQuery('SELECT 1', 1.0);
        $pulse->startTimer('work');
        $pulse->endTimer('work');
        $pulse->recordOutboundRequest('https://example.test/', 'GET', 200, 1.0);
        $pulse->recordException(new RuntimeException('still recorded'));
        $pulse->recordMetric('orders.created', 1.0);
        $pulse->flush();

        $pdo = $this->pdo($pulse);
        foreach (['pulse_requests', 'pulse_queries', 'pulse_spans', 'pulse_outbound_requests'] as $table) {
            self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        }
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM pulse_exceptions')->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM pulse_metrics')->fetchColumn());
    }

    public function test_ignored_paths_support_wildcards(): void
    {
        $pulse = Pulse::init(':memory:', false, null, ['ignore_paths' => ['/health', 'pulse/*']]);

        self::assertTrue($pulse->isIgnoredPath('/health'));
        self::assertTrue($pulse->isIgnoredPath('/pulse/data'));
        self::assertFalse($pulse->isIgnoredPath('/healthy'));
        self::assertFalse($pulse->isIgnoredPath('/orders'));

        $pulse->beginTrace('pulse.data', 'GET', '/pulse/data');
        self::assertFalse($pulse->isRecording());
        $pulse->beginTrace('orders', 'GET', '/orders');
        self::assertTrue($pulse->isRecording());
    }

    public function test_a_full_buffer_is_written_early(): void
    {
        $pulse = Pulse::init(':memory:', false, null, ['max_buffer_size' => 3]);
        $pdo = $this->pdo($pulse);

        $pulse->recordMetric('a', 1.0);
        $pulse->recordMetric('b', 1.0);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM pulse_metrics')->fetchColumn());

        $pulse->recordMetric('c', 1.0);
        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM pulse_metrics')->fetchColumn());
    }

    public function test_storage_failures_are_reported_instead_of_thrown(): void
    {
        $pulse = Pulse::init(':memory:', false);
        (new ReflectionProperty(Pulse::class, 'storage'))->setValue($pulse, new class () implements StorageInterface {
            public function writeBatch(array $buffer): void
            {
                throw new RuntimeException('disk full');
            }

            public function prune(int $retentionDays, bool $vacuum = false): array
            {
                return [];
            }
        });
        $reported = [];
        $pulse->reportErrorsUsing(static function (string $message, Throwable $error) use (&$reported): void {
            $reported[] = $error->getMessage();
        });

        $pulse->recordMetric('lost', 1.0);
        $pulse->flush();
        $pulse->flush();

        self::assertSame(['disk full'], $reported, 'The failed batch is dropped, not retried forever.');
    }

    public function test_it_rejects_unknown_options(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Pulse::init(':memory:', false, null, ['sample' => 0.5]);
    }

    private function failure(string $message): RuntimeException
    {
        return new RuntimeException($message);
    }

    private function pdo(Pulse $pulse): PDO
    {
        $storage = (new ReflectionProperty(Pulse::class, 'storage'))->getValue($pulse);

        return (new ReflectionProperty(SQLiteStorage::class, 'pdo'))->getValue($storage);
    }
}
