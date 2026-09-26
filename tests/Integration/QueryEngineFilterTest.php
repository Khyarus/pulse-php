<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PulsePHP\Storage\QueryEngine;
use PulsePHP\Storage\SQLiteStorage;

final class QueryEngineFilterTest extends TestCase
{
    public function test_it_filters_queries_by_period_service_and_route(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'pulse-filters-');
        self::assertNotFalse($dbPath);
        $storage = null;
        $queries = null;
        $pdo = null;

        try {
            $storage = new SQLiteStorage($dbPath);
            $storage->writeBatch([
                'metrics' => [
                    ['name' => 'orders.created', 'value' => 1.0, 'service' => 'orders-service', 'route' => 'orders.show'],
                ],
                'spans' => [
                    [
                        'name' => 'http.outbound:payments.example.test',
                        'duration_ms' => 30.0,
                        'memory_bytes' => 0,
                        'service' => 'orders-service',
                        'route' => 'orders.show',
                    ],
                    [
                        'name' => 'http.outbound:search.example.test',
                        'duration_ms' => 80.0,
                        'memory_bytes' => 0,
                        'service' => 'catalog-service',
                        'route' => 'products.search',
                    ],
                ],
                'exceptions' => [
                    [
                        'message' => 'orders failure',
                        'file' => 'OrdersController.php',
                        'line' => 20,
                        'trace' => 'trace',
                        'service' => 'orders-service',
                        'route' => 'orders.show',
                    ],
                    [
                        'message' => 'unhandled payment failure',
                        'file' => 'OrdersController.php',
                        'line' => 42,
                        'trace' => 'trace',
                        'method' => 'POST',
                        'unhandled' => true,
                        'service' => 'orders-service',
                        'route' => 'orders.show',
                    ],
                ],
                'requests' => [
                    [
                        'url' => '/orders/1', 'method' => 'GET', 'status_code' => 200,
                        'duration_ms' => 30.0, 'memory_bytes' => 1024, 'ip' => '127.0.0.1',
                        'service' => 'orders-service', 'route' => 'orders.show',
                    ],
                    [
                        'url' => '/orders/2', 'method' => 'GET', 'status_code' => 200,
                        'duration_ms' => 90.0, 'memory_bytes' => 2048, 'ip' => '127.0.0.1',
                        'service' => 'orders-service', 'route' => 'orders.show',
                    ],
                    [
                        'url' => '/orders', 'method' => 'POST', 'status_code' => 500,
                        'duration_ms' => 600.0, 'memory_bytes' => 2048, 'ip' => '127.0.0.1',
                        'service' => 'orders-service', 'route' => 'orders.show',
                    ],
                    [
                        'url' => '/products/search', 'method' => 'GET', 'status_code' => 200,
                        'duration_ms' => 80.0, 'memory_bytes' => 1024, 'ip' => '127.0.0.1',
                        'service' => 'catalog-service', 'route' => 'products.search',
                    ],
                ],
                'queries' => [
                    [
                        'hash' => md5('SELECT * FROM orders WHERE id = ?'),
                        'normalized_sql' => 'SELECT * FROM orders WHERE id = ?',
                        'duration_ms' => 30.0,
                        'service' => 'orders-service',
                        'route' => 'orders.show',
                    ],
                    [
                        'hash' => md5('SELECT * FROM products WHERE name = ?'),
                        'normalized_sql' => 'SELECT * FROM products WHERE name = ?',
                        'duration_ms' => 80.0,
                        'service' => 'catalog-service',
                        'route' => 'products.search',
                    ],
                ],
                'outbound_requests' => [
                    [
                        'service' => 'orders-service', 'route' => 'orders.show', 'method' => 'GET',
                        'status_code' => 200, 'url' => 'https://payments.example.test/health', 'duration_ms' => 30.0,
                    ],
                    [
                        'service' => 'catalog-service', 'route' => 'products.search', 'method' => 'POST',
                        'status_code' => 503, 'url' => 'https://search.example.test/query', 'duration_ms' => 80.0,
                    ],
                ],
            ]);

            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->exec("UPDATE pulse_requests SET created_at = datetime('now', '-2 days') WHERE url = '/orders/2'");
            $queries = new QueryEngine($dbPath);

            $filteredStats = $queries->getSummaryStats('24h', 'orders-service', 'orders.show');
            self::assertSame(2, $filteredStats['requests_total']);
            self::assertSame(2, $filteredStats['exceptions_total']);
            self::assertSame(3, $queries->getSummaryStats('all', 'orders-service', 'orders.show')['requests_total']);
            self::assertSame(3, $queries->getSummaryStats('all', 'orders-service')['requests_total']);

            self::assertSame(
                'SELECT * FROM orders WHERE id = ?',
                $queries->getSlowestQueries(10, '24h', 'orders-service', 'orders.show')[0]['normalized_sql']
            );
            self::assertSame(
                'http.outbound:payments.example.test',
                $queries->getTopSpans(10, '24h', 'orders-service', 'orders.show')[0]['name']
            );
            self::assertSame(2, count($queries->getLatestExceptions(10, '24h', 'orders-service', 'orders.show')));
            self::assertSame(2, array_sum(array_column(
                $queries->getRequestTimeline('24h', 'orders-service', 'orders.show'),
                'requests'
            )));
            self::assertSame(2, count($queries->getRecentRequests(100, '24h', 'orders-service', 'orders.show')));
            self::assertSame(1, count($queries->getOutboundRequests(100, '24h', 'orders-service', 'orders.show')));
            self::assertEquals(['catalog-service', 'orders-service'], $queries->getAvailableServices());
            self::assertContains('orders.show', $queries->getAvailableRoutes('orders-service'));

            $from = gmdate('Y-m-d H:i:s', time() - 86400);
            $to = gmdate('Y-m-d H:i:s', time() + 5);
            $routeMetrics = $queries->getMetricsByRoute($from, $to, 'orders-service');
            $metricsByMethod = array_column($routeMetrics, null, 'method');
            self::assertSame(1, $metricsByMethod['GET']['total_requests']);
            self::assertSame(1, $metricsByMethod['POST']['total_requests']);
            self::assertEqualsWithDelta(100.0, $metricsByMethod['POST']['error_rate_percent'], 0.001);
            self::assertSame(1, $metricsByMethod['POST']['unhandled_exceptions']);
            self::assertSame(1, array_sum(array_column($metricsByMethod['POST']['timeline'], 'requests')));

            $slowRequests = $queries->getSlowestRequestsByRoute(
                $from,
                $to,
                'orders-service',
                'orders.show',
                'POST'
            );
            self::assertSame(500, $slowRequests[0]['status_code']);
            self::assertSame(600.0, $slowRequests[0]['duration_ms']);
        } finally {
            unset($queries, $pdo, $storage);
            if (is_file($dbPath)) {
                unlink($dbPath);
            }
        }
    }
}