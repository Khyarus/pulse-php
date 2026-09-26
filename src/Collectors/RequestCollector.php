<?php

declare(strict_types=1);

namespace PulsePHP\Collectors;

use PulsePHP\Pulse;

final class RequestCollector
{
    private int $startedAt;
    private int $memoryAtStart;
    private bool $collected = false;

    public function __construct(private Pulse $pulse)
    {
        $this->startedAt = hrtime(true);
        $this->memoryAtStart = memory_get_usage(true);
    }

    public function collect(): void
    {
        if ($this->collected || (!isset($_SERVER['REQUEST_URI']) && PHP_SAPI === 'cli')) {
            return;
        }

        $this->collected = true;
        $status = http_response_code();

        $this->pulse->recordRequest(
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
            is_int($status) ? $status : 200,
            (hrtime(true) - $this->startedAt) / 1_000_000,
            max(0, memory_get_peak_usage(true) - $this->memoryAtStart),
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null
        );
    }
}