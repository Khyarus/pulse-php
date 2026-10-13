<?php

declare(strict_types=1);

namespace PulsePHP\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PulsePHP\Pulse;

/**
 * Monolog 3 handler that stores log lines in PulsePHP, linked to the current
 * request or job.
 *
 *     $logger->pushHandler(new PulseLogHandler(Level::Warning));
 */
final class PulseLogHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Warning, bool $bubble = true, private ?Pulse $pulse = null)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $pulse = $this->pulse ?? (Pulse::isInitialized() ? Pulse::getInstance() : null);
        $pulse?->recordLog($record->level->toPsrLogLevel(), $record->message, $record->context);
    }
}
