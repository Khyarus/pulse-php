<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulsePHP\Dashboard\Dashboard;
use PulsePHP\Storage\SQLiteStorage;

final class DashboardAuthTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulse-dashboard-');
        self::assertNotFalse($path);
        $this->dbPath = $path;
        new SQLiteStorage($this->dbPath);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_GET = [];
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION']);
        $_GET = [];
        if (isset($this->dbPath) && is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function test_it_denies_access_when_no_policy_is_configured(): void
    {
        [$status, $html] = $this->render(new Dashboard($this->dbPath));

        self::assertSame(403, $status);
        self::assertStringContainsString('Access denied', $html);
        self::assertStringNotContainsString('Application pulse', $html);
    }

    public function test_it_requires_valid_basic_auth_credentials(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'pulse-admin';
        $_SERVER['PHP_AUTH_PW'] = 'wrong';

        [$status] = $this->render(
            (new Dashboard($this->dbPath))->authWithBasic('pulse-admin', 'correct')
        );

        self::assertSame(401, $status);
    }

    public function test_it_rejects_an_ip_outside_the_whitelist(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';

        [$status] = $this->render(
            (new Dashboard($this->dbPath))->authWithIp(['127.0.0.1', '::1'])
        );

        self::assertSame(403, $status);
    }

    public function test_it_uses_the_authorization_callback_as_the_gate_boundary(): void
    {
        [$deniedStatus] = $this->render(
            (new Dashboard($this->dbPath))->authorize(static fn (): bool => false)
        );
        self::assertSame(403, $deniedStatus);

        [$allowedStatus, $html] = $this->render(
            (new Dashboard($this->dbPath))->authorize(static fn (): bool => true)
        );
        self::assertSame(200, $allowedStatus);
        self::assertStringContainsString('Application pulse', $html);
    }

    public function test_it_filters_dashboard_data_by_service_and_route(): void
    {
        $storage = new SQLiteStorage($this->dbPath);
        $storage->writeBatch([
            'requests' => [
                [
                    'url' => '/orders/42', 'method' => 'GET', 'status_code' => 200,
                    'duration_ms' => 12.0, 'memory_bytes' => 1024, 'ip' => '127.0.0.1',
                    'service' => 'orders-api', 'route' => 'orders.show',
                ],
                [
                    'url' => '/orders', 'method' => 'POST', 'status_code' => 500,
                    'duration_ms' => 650.0, 'memory_bytes' => 2048, 'ip' => '127.0.0.1',
                    'service' => 'orders-api', 'route' => 'orders.show',
                ],
                [
                    'url' => '/catalog/search', 'method' => 'GET', 'status_code' => 200,
                    'duration_ms' => 25.0, 'memory_bytes' => 1024, 'ip' => '127.0.0.1',
                    'service' => 'catalog-api', 'route' => 'catalog.search',
                ],
            ],
            'exceptions' => [[
                'message' => 'unhandled order failure',
                'file' => 'OrdersController.php',
                'line' => 42,
                'trace' => 'trace',
                'method' => 'POST',
                'unhandled' => true,
                'service' => 'orders-api',
                'route' => 'orders.show',
            ]],
        ]);
        unset($storage);
        $_GET = [
            'period' => '24h',
            'service' => 'orders-api',
            'route' => 'orders.show',
        ];

        [$status, $html] = $this->render(
            (new Dashboard($this->dbPath))->authorize(static fn (): bool => true)
        );

        self::assertSame(200, $status);
        self::assertStringContainsString('value="24h" selected', $html);
        self::assertStringContainsString('value="orders-api" selected', $html);
        self::assertStringContainsString('value="orders.show" selected', $html);
        self::assertStringContainsString('Rotas &amp; APIs Monitoradas', $html);
        self::assertStringContainsString('data-severity="healthy"', $html);
        self::assertStringContainsString('data-severity="critical"', $html);
        self::assertStringContainsString('data-method="GET"', $html);
        self::assertStringContainsString('Unhandled exceptions: 1', $html);
        self::assertStringContainsString('/orders/42', $html);
        self::assertStringNotContainsString('/catalog/search', $html);
        self::assertStringContainsString('pulse-dashboard-v2', $html);
        self::assertStringContainsString('data-sortable', $html);
    }

    public function test_it_marks_a_route_warning_at_the_latency_threshold(): void
    {
        $storage = new SQLiteStorage($this->dbPath);
        $storage->writeBatch([
            'requests' => [[
                'url' => '/reports',
                'method' => 'PUT',
                'status_code' => 200,
                'duration_ms' => 300.0,
                'memory_bytes' => 1024,
                'ip' => '127.0.0.1',
                'service' => 'reports-api',
                'route' => 'reports.update',
            ]],
        ]);
        unset($storage);
        $_GET = ['service' => 'reports-api'];

        [, $html] = $this->render(
            (new Dashboard($this->dbPath))->authorize(static fn (): bool => true)
        );

        self::assertStringContainsString('data-severity="warning"', $html);
        self::assertStringContainsString('data-method="PUT"', $html);
    }

    /** @return array{int, string} */
    private function render(Dashboard $dashboard): array
    {
        http_response_code(200);
        ob_start();
        $dashboard->render();
        $html = (string) ob_get_clean();

        return [http_response_code(), $html];
    }
}