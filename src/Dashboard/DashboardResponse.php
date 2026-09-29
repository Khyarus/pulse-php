<?php

declare(strict_types=1);

namespace PulsePHP\Dashboard;

/**
 * Immutable value object describing a dashboard HTTP response.
 *
 * It decouples the response from PHP's global request state so integrations
 * (Laravel, Symfony, PSR-7, ...) can map {@see self::$status} and
 * {@see self::$headers} onto their own HTTP abstractions.
 */
final class DashboardResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        private readonly array $headers = [],
    ) {
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
