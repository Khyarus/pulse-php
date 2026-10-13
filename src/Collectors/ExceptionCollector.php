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
        $this->pulse->recordException($exception, true);

        if (is_callable($this->previousExceptionHandler)) {
            ($this->previousExceptionHandler)($exception);

            return;
        }

        // Without a previous handler PHP's default behaviour must survive:
        // log the uncaught exception and end with a failure status. Rethrowing
        // from inside the handler re-enters it on some PHP versions, so the
        // default is reproduced explicitly instead.
        restore_exception_handler();
        $this->pulse->flush();

        if (!headers_sent() && PHP_SAPI !== 'cli') {
            http_response_code(500);
        }
        error_log('PHP Fatal error:  Uncaught ' . $exception);

        exit(255);
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
