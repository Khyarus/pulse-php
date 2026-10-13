<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulsePHP\Dashboard\Dashboard;
use PulsePHP\Storage\SQLiteStorage;

final class DashboardPagesTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulse-pages-');
        self::assertNotFalse($path);
        unlink($path);
        $this->dbPath = $path . '.sqlite';
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        foreach (glob($this->dbPath . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_it_works_before_any_telemetry_exists(): void
    {
        self::assertFileDoesNotExist($this->dbPath);

        $response = $this->dashboard()->handle();

        self::assertSame(200, $response->status);
        self::assertFileExists($this->dbPath);
    }

    public function test_it_bundles_assets_instead_of_loading_them_from_a_cdn(): void
    {
        $html = $this->dashboard()->handle()->body;

        self::assertStringNotContainsString('cdn.tailwindcss.com', $html);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $html);
        self::assertStringNotContainsString('fonts.googleapis.com', $html);
        self::assertMatchesRegularExpression('/src="\?asset=chart\.js&amp;v=[a-f0-9]{12}"/', $html);

        $_GET = ['asset' => 'chart.js'];
        $chart = $this->dashboard()->handle();
        self::assertSame(200, $chart->status);
        self::assertStringContainsString('Chart.js v4', $chart->body);
        self::assertStringStartsWith('text/javascript', $chart->headers()['Content-Type']);

        $_GET = ['asset' => 'fonts/plex-sans-400.woff2'];
        self::assertSame('font/woff2', $this->dashboard()->handle()->headers()['Content-Type']);

        $_GET = ['asset' => '../../Dashboard.php'];
        self::assertSame(404, $this->dashboard()->handle()->status, 'Only bundled assets are served.');
    }

    public function test_every_screen_renders(): void
    {
        foreach (['requests', 'errors', 'queries', 'jobs', 'outbound', 'logs', 'settings'] as $view) {
            $_GET = ['view' => $view];
            $response = $this->dashboard()->handle();
            self::assertSame(200, $response->status, $view);
            self::assertStringContainsString('aria-current="page"', $response->body, $view);
        }
    }

    public function test_the_live_refresh_endpoint_is_configurable(): void
    {
        $html = $this->dashboard()->withDataEndpoint('/admin/pulse/data')->handle()->body;

        self::assertStringContainsString('const pulseDataEndpoint = "/admin/pulse/data";', $html);
        self::assertStringNotContainsString("new URL('dashboard-data.php'", $html);
    }

    public function test_the_json_snapshot_includes_exception_groups(): void
    {
        (new SQLiteStorage($this->dbPath))->writeBatch(['exceptions' => [[
            'class' => 'App\\Boom', 'message' => 'boom', 'file' => 'x.php', 'line' => 1, 'trace' => '',
        ]]]);

        $snapshot = json_decode($this->dashboard()->handleJson()->body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('App\\Boom', $snapshot['exceptionGroups'][0]['class']);
        self::assertArrayHasKey('p95_response_ms', $snapshot['stats']);
    }

    public function test_it_renders_a_trace_and_returns_404_for_unknown_ones(): void
    {
        (new SQLiteStorage($this->dbPath))->writeBatch([
            'requests' => [[
                'url' => '/checkout', 'method' => 'POST', 'status_code' => 201, 'duration_ms' => 42.0,
                'memory_bytes' => 0, 'ip' => null, 'trace_id' => 'abcdef0123456789',
            ]],
            'queries' => [[
                'hash' => 'h', 'normalized_sql' => 'INSERT INTO orders VALUES (?)', 'duration_ms' => 3.0,
                'trace_id' => 'abcdef0123456789',
            ]],
        ]);

        $_GET = ['trace' => 'abcdef0123456789'];
        $found = $this->dashboard()->handle();
        self::assertSame(200, $found->status);
        self::assertStringContainsString('POST /checkout', $found->body);
        self::assertStringContainsString('INSERT INTO orders VALUES (?)', $found->body);

        $_GET = ['trace' => '0000000000000000'];
        self::assertSame(404, $this->dashboard()->handle()->status);

        $_GET = ['trace' => '<img src=x onerror=alert(1)>'];
        $invalid = $this->dashboard()->handle();
        self::assertSame(404, $invalid->status);
        self::assertStringNotContainsString('<img src=x', $invalid->body);
    }

    public function test_a_trace_page_still_requires_authorization(): void
    {
        $_GET = ['trace' => 'abcdef0123456789'];

        self::assertSame(403, (new Dashboard($this->dbPath))->handle()->status);
    }

    private function dashboard(): Dashboard
    {
        return (new Dashboard($this->dbPath))->authorize(static fn (): bool => true);
    }
}
