<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulsePHP\Storage\QueryEngine;
use PulsePHP\Storage\SQLiteStorage;

final class InsightsTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulse-insights-');
        self::assertNotFalse($path);
        $this->dbPath = $path;
        $requests = [];
        foreach (range(1, 100) as $duration) {
            $requests[] = [
                'url' => '/orders', 'method' => 'GET', 'status_code' => 200,
                'duration_ms' => (float) $duration, 'memory_bytes' => 0, 'ip' => null,
                'service' => 'shop', 'route' => 'orders.index', 'trace_id' => $duration === 7 ? 'aaaa000000000007' : '',
            ];
        }

        $queries = [];
        foreach (range(1, 12) as $_) {
            $queries[] = [
                'hash' => 'lines', 'normalized_sql' => 'SELECT * FROM lines WHERE order_id = ?',
                'duration_ms' => 0.5, 'service' => 'shop', 'route' => 'orders.index', 'trace_id' => 'aaaa000000000007',
            ];
        }
        $queries[] = [
            'hash' => 'orders', 'normalized_sql' => 'SELECT * FROM orders',
            'duration_ms' => 2.0, 'service' => 'shop', 'route' => 'orders.index', 'trace_id' => 'aaaa000000000007',
        ];

        (new SQLiteStorage($this->dbPath))->writeBatch([
            'requests' => $requests,
            'queries' => $queries,
            'exceptions' => [
                ['class' => 'App\\Failure', 'message' => 'first', 'file' => 'a.php', 'line' => 1, 'trace' => '', 'unhandled' => true, 'trace_id' => 'aaaa000000000007'],
                ['class' => 'App\\Failure', 'message' => 'second', 'file' => 'a.php', 'line' => 1, 'trace' => '', 'unhandled' => false],
                ['class' => 'App\\Other', 'message' => 'other', 'file' => 'b.php', 'line' => 9, 'trace' => ''],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dbPath . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_it_reports_latency_percentiles(): void
    {
        $queries = new QueryEngine($this->dbPath);
        $stats = $queries->getSummaryStats('24h');

        self::assertSame(95.0, $stats['p95_response_ms']);
        self::assertSame(99.0, $stats['p99_response_ms']);

        $routes = $queries->getMetricsByRoute(gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 60));
        self::assertSame(95.0, $routes[0]['p95_duration_ms']);
    }

    public function test_it_groups_exceptions_by_fingerprint(): void
    {
        $groups = (new QueryEngine($this->dbPath))->getExceptionGroups();
        $byClass = array_column($groups, null, 'class');

        self::assertCount(2, $groups);
        self::assertSame(2, $byClass['App\\Failure']['occurrences']);
        self::assertSame(1, $byClass['App\\Failure']['unhandled']);
        self::assertSame('second', $byClass['App\\Failure']['message'], 'The latest message is shown.');
    }

    public function test_it_flags_queries_repeated_inside_one_trace(): void
    {
        $patterns = (new QueryEngine($this->dbPath))->getNPlusOneQueries(5);

        self::assertCount(1, $patterns);
        self::assertSame('SELECT * FROM lines WHERE order_id = ?', $patterns[0]['normalized_sql']);
        self::assertSame(12, $patterns[0]['max_per_trace']);
        self::assertSame('aaaa000000000007', $patterns[0]['sample_trace_id']);
    }

    public function test_it_loads_everything_recorded_for_a_trace(): void
    {
        $queries = new QueryEngine($this->dbPath);
        $trace = $queries->getTrace('aaaa000000000007');

        self::assertNotNull($trace);
        self::assertSame(7.0, (float) $trace['request']['duration_ms']);
        self::assertCount(13, $trace['queries']);
        self::assertCount(1, $trace['exceptions']);
        self::assertNull($queries->getTrace('ffff000000000000'));
        self::assertNull($queries->getTrace("' OR 1=1 --"));
    }

    public function test_window_stats_feed_alert_rules(): void
    {
        $stats = (new QueryEngine($this->dbPath))->getWindowStats(5);

        self::assertSame(100, $stats['requests']);
        self::assertSame(0, $stats['server_errors']);
        self::assertSame(3, $stats['exceptions']);
        self::assertSame(95.0, $stats['p95_ms']);
    }
}
