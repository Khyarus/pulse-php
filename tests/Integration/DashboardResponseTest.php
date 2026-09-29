<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulsePHP\Dashboard\Dashboard;
use PulsePHP\Dashboard\DashboardResponse;
use PulsePHP\Storage\SQLiteStorage;

final class DashboardResponseTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulse-response-');
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

    public function test_handle_returns_html_without_emitting_it(): void
    {
        $dashboard = (new Dashboard($this->dbPath))->authorize(static fn (): bool => true);

        ob_start();
        $response = $dashboard->handle();
        $leaked = (string) ob_get_clean();

        self::assertInstanceOf(DashboardResponse::class, $response);
        self::assertSame('', $leaked, 'handle() must not echo the response body');
        self::assertSame(200, $response->status);
        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString('Application pulse', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('no-store, private', $response->headers()['Cache-Control']);
    }

    public function test_handle_reports_access_denied_without_emitting(): void
    {
        $dashboard = (new Dashboard($this->dbPath))->authorize(static fn (): bool => false);

        ob_start();
        $response = $dashboard->handle();
        $leaked = (string) ob_get_clean();

        self::assertSame('', $leaked);
        self::assertSame(403, $response->status);
        self::assertFalse($response->isSuccessful());
        self::assertStringContainsString('Access denied', $response->body);
    }

    public function test_handle_json_returns_a_decodable_body_and_auth_header(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'pulse-admin';
        $_SERVER['PHP_AUTH_PW'] = 'wrong';
        $dashboard = (new Dashboard($this->dbPath))
            ->authWithBasic('pulse-admin', 'correct');

        $denied = $dashboard->handleJson();

        self::assertSame(401, $denied->status);
        self::assertSame(
            'Basic realm="PulsePHP Dashboard", charset="UTF-8"',
            $denied->headers()['WWW-Authenticate']
        );
        self::assertSame(['error' => 'Access denied'], json_decode($denied->body, true, 512, JSON_THROW_ON_ERROR));

        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        $allowed = (new Dashboard($this->dbPath))
            ->authorize(static fn (): bool => true)
            ->handleJson();

        self::assertSame(200, $allowed->status);
        self::assertArrayNotHasKey('WWW-Authenticate', $allowed->headers());
        self::assertIsArray(json_decode($allowed->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_render_emits_the_same_payload_as_handle(): void
    {
        $dashboard = (new Dashboard($this->dbPath))->authorize(static fn (): bool => true);

        http_response_code(200);
        ob_start();
        $dashboard->render();
        $emitted = (string) ob_get_clean();
        $status = http_response_code();

        $handled = (new Dashboard($this->dbPath))
            ->authorize(static fn (): bool => true)
            ->handle();

        self::assertSame($handled->status, $status);
        self::assertSame($handled->body, $emitted);
    }
}
