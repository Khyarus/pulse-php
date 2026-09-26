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
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION']);
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