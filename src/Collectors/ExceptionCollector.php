<?php

declare(strict_types=1);

namespace PulsePHP\Collectors;

use ErrorException;
use PulsePHP\Pulse;
use Throwable;

final class ExceptionCollector
{
    private mixed $previousExceptionHandler = null;
    private mixed $previousErrorHandler = null;

    public function __construct(private Pulse $pulse)
    {
    }

    public function register(): void
    {
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
    }

    public function handleException(Throwable $exception): void
    {
        $this->pulse->recordException($exception);

        if (is_callable($this->previousExceptionHandler)) {
            ($this->previousExceptionHandler)($exception);
        }
    }

    public function handleError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $this->pulse->recordException(new ErrorException($message, 0, $severity, $file, $line));

        if (is_callable($this->previousErrorHandler)) {
            return (bool) ($this->previousErrorHandler)($severity, $message, $file, $line);
        }

        return false;
    }
}