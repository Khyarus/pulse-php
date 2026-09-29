<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulsePHP\Dashboard\Dashboard;
use PulsePHP\Storage\SQLiteStorage;

final class DashboardEscapingTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulse-xss-');
        self::assertNotFalse($path);
        $this->dbPath = $path;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_GET = [];
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        if (isset($this->dbPath) && is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function test_it_escapes_injected_script_tags_in_route_data(): void
    {
        $breakout = '</script><script>alert(1)</script>';
        $quoted = '"><img src=x onerror=alert(1)>';

        $storage = new SQLiteStorage($this->dbPath);
        $storage->writeBatch([
            'requests' => [[
                'url' => '/search?q=' . $breakout,
                'method' => 'GET',
                'status_code' => 200,
                'duration_ms' => 12.0,
                'memory_bytes' => 1024,
                'ip' => '127.0.0.1',
                'service' => $quoted,
                'route' => $breakout,
            ]],
            'metrics' => [[
                'name' => 'events',
                'value' => 1.0,
                'tags' => ['label' => $breakout],
            ]],
        ]);
        unset($storage);

        [$status, $html] = $this->render(
            (new Dashboard($this->dbPath))->authorize(static fn (): bool => true)
        );

        self::assertSame(200, $status);

        // The literal </script> must never appear inside the inline JSON payload.
        $payloadStart = strpos($html, 'const pulseInitialLiveData');
        self::assertNotFalse($payloadStart);
        $payloadEnd = strpos($html, 'window.pulseDashboard', $payloadStart);
        self::assertNotFalse($payloadEnd);
        $payload = substr($html, $payloadStart, $payloadEnd - $payloadStart);

        self::assertStringNotContainsString('</script>', $payload);
        self::assertStringNotContainsString('<img', $payload);
        self::assertStringContainsString('\\u003C', $payload); // JSON_HEX_TAG applied

        // Raw HTML breakout from the quoted payload must be neutralized.
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
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
