<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulsePHP\Alerts\AlertManager;
use PulsePHP\Storage\SQLiteStorage;
use RuntimeException;

final class AlertManagerTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulse-alerts-');
        self::assertNotFalse($path);
        $this->dbPath = $path;

        $requests = [];
        foreach (range(1, 40) as $index) {
            $requests[] = [
                'url' => '/pay', 'method' => 'POST', 'status_code' => $index <= 4 ? 500 : 200,
                'duration_ms' => 100.0, 'memory_bytes' => 0, 'ip' => null,
            ];
        }
        (new SQLiteStorage($this->dbPath))->writeBatch(['requests' => $requests]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dbPath . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_it_triggers_rules_over_their_threshold(): void
    {
        $alerts = (new AlertManager($this->dbPath, ['error_rate_percent' => 5.0, 'p95_ms' => 50]))->evaluate();

        self::assertSame(['error_rate', 'p95_latency'], array_column($alerts, 'rule'));
        self::assertEqualsWithDelta(10.0, $alerts[0]['value'], 0.001);
    }

    public function test_rules_can_be_disabled_and_need_minimum_traffic(): void
    {
        self::assertSame([], (new AlertManager($this->dbPath, [
            'error_rate_percent' => null,
            'p95_ms' => null,
        ]))->evaluate());

        self::assertSame([], (new AlertManager($this->dbPath, [
            'min_requests' => 1000,
            'exceptions' => null,
        ]))->evaluate());
    }

    public function test_notifications_respect_the_cooldown(): void
    {
        $sent = [];
        $manager = (new AlertManager($this->dbPath, ['p95_ms' => null], 'payments'))
            ->notifyUsing(static function (array $alerts, string $summary) use (&$sent): void {
                $sent[] = $summary;
            });

        self::assertCount(1, $manager->check());
        self::assertCount(0, $manager->check(), 'A rule inside its cooldown is not sent again.');
        self::assertCount(1, $sent);
        self::assertStringContainsString('payments', $sent[0]);
        self::assertStringContainsString('5xx error rate is 10.0%', $sent[0]);
    }

    public function test_a_failing_channel_does_not_start_the_cooldown(): void
    {
        $manager = (new AlertManager($this->dbPath, ['p95_ms' => null]))
            ->notifyUsing(static function (): void {
                throw new RuntimeException('webhook down');
            });

        $previousLog = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $manager->check();
            self::assertCount(1, $manager->check(), 'Undelivered alerts are retried on the next run.');
        } finally {
            ini_set('error_log', (string) $previousLog);
        }
    }
}
