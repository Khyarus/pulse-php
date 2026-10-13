<?php

declare(strict_types=1);

namespace PulsePHP\Alerts;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PulsePHP\Storage\QueryEngine;
use PulsePHP\Storage\SQLiteStorage;
use Throwable;

/**
 * Evaluates threshold rules over a recent time window and notifies channels.
 *
 * Meant to run from a scheduler (`php artisan pulse:check` or
 * `vendor/bin/pulse check`) — never inside a web request.
 */
final class AlertManager
{
    private const DEFAULT_RULES = [
        'window_minutes' => 5,
        'cooldown_minutes' => 30,
        'min_requests' => 20,
        'error_rate_percent' => 5.0,
        'exceptions' => 10,
        'p95_ms' => 2000,
    ];

    /** @var array<string, int|float|null> */
    private array $rules;

    /** @var list<Closure(list<array{rule: string, message: string, value: float, threshold: float}>, string): void> */
    private array $channels = [];

    private SQLiteStorage $storage;
    private QueryEngine $queries;

    /** @param array<string, int|float|null> $rules */
    public function __construct(string $dbPath, array $rules = [], private string $service = 'default')
    {
        $this->storage = new SQLiteStorage($dbPath);
        $this->queries = new QueryEngine($dbPath);
        $this->rules = array_intersect_key($rules, self::DEFAULT_RULES) + self::DEFAULT_RULES;
    }

    /**
     * Adds a channel that receives the triggered alerts and a plain-text summary.
     *
     * @param Closure(list<array{rule: string, message: string, value: float, threshold: float}>, string): void $channel
     */
    public function notifyUsing(Closure $channel): self
    {
        $this->channels[] = $channel;

        return $this;
    }

    public function notifyWebhook(string $url, string $format = 'json', int $timeoutSeconds = 5): self
    {
        return $this->notifyUsing(function (array $alerts, string $summary) use ($url, $format, $timeoutSeconds): void {
            $payload = $format === 'slack'
                ? ['text' => $summary]
                : [
                    'source' => 'pulse-php',
                    'service' => $this->service,
                    'generated_at' => gmdate('c'),
                    'alerts' => $alerts,
                ];

            WebhookSender::post($url, $payload, $timeoutSeconds);
        });
    }

    /**
     * Returns every rule currently over its threshold, ignoring cooldowns.
     *
     * @return list<array{rule: string, message: string, value: float, threshold: float}>
     */
    public function evaluate(): array
    {
        $window = (int) $this->rules['window_minutes'];
        $stats = $this->queries->getWindowStats($window);
        $alerts = [];
        $enoughTraffic = $stats['requests'] >= (int) $this->rules['min_requests'];

        $errorRate = $this->rules['error_rate_percent'];
        if ($errorRate !== null && $enoughTraffic && $stats['error_rate_percent'] >= (float) $errorRate) {
            $alerts[] = $this->alert(
                'error_rate',
                sprintf('5xx error rate is %.1f%% over the last %d min (threshold %.1f%%).', $stats['error_rate_percent'], $window, $errorRate),
                $stats['error_rate_percent'],
                (float) $errorRate
            );
        }

        $exceptions = $this->rules['exceptions'];
        if ($exceptions !== null && $stats['exceptions'] >= (int) $exceptions) {
            $alerts[] = $this->alert(
                'exceptions',
                sprintf('%d exceptions in the last %d min (threshold %d).', $stats['exceptions'], $window, $exceptions),
                (float) $stats['exceptions'],
                (float) $exceptions
            );
        }

        $p95 = $this->rules['p95_ms'];
        if ($p95 !== null && $enoughTraffic && $stats['p95_ms'] >= (float) $p95) {
            $alerts[] = $this->alert(
                'p95_latency',
                sprintf('p95 latency is %.0f ms over the last %d min (threshold %.0f ms).', $stats['p95_ms'], $window, $p95),
                $stats['p95_ms'],
                (float) $p95
            );
        }

        return $alerts;
    }

    /**
     * Evaluates rules, notifies channels about alerts outside their cooldown
     * and returns the alerts that were sent.
     *
     * @return list<array{rule: string, message: string, value: float, threshold: float}>
     */
    public function check(): array
    {
        $due = array_values(array_filter($this->evaluate(), fn (array $alert): bool => !$this->coolingDown($alert['rule'])));
        if ($due === []) {
            return [];
        }

        $summary = sprintf("[PulsePHP] %s — %d alert(s)\n", $this->service, count($due))
            . implode("\n", array_map(static fn (array $alert): string => '• ' . $alert['message'], $due));

        $delivered = $this->channels === [];
        foreach ($this->channels as $channel) {
            try {
                $channel($due, $summary);
                $delivered = true;
            } catch (Throwable $exception) {
                error_log('[PulsePHP] Alert channel failed: ' . $exception->getMessage());
            }
        }

        // Cooldown starts only once someone was actually told.
        if ($delivered) {
            foreach ($due as $alert) {
                $this->storage->markAlert($alert['rule']);
            }
        }

        return $due;
    }

    private function coolingDown(string $rule): bool
    {
        $last = $this->storage->lastAlertAt($rule);
        if ($last === null) {
            return false;
        }

        $utc = new DateTimeZone('UTC');
        $elapsed = (new DateTimeImmutable('now', $utc))->getTimestamp()
            - (new DateTimeImmutable($last, $utc))->getTimestamp();

        return $elapsed < 60 * (int) $this->rules['cooldown_minutes'];
    }

    /** @return array{rule: string, message: string, value: float, threshold: float} */
    private function alert(string $rule, string $message, float $value, float $threshold): array
    {
        return ['rule' => $rule, 'message' => $message, 'value' => $value, 'threshold' => $threshold];
    }
}
