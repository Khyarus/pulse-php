<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Console;

use Illuminate\Console\Command;
use PulsePHP\Pulse;

final class PruneCommand extends Command
{
    protected $signature = 'pulse:prune
        {--days= : Keep this many days of telemetry (defaults to pulse.retention_days)}
        {--vacuum : Reclaim disk space after deleting}';

    protected $description = 'Delete PulsePHP telemetry older than the retention window';

    public function handle(Pulse $pulse): int
    {
        $days = $this->option('days') ?? config('pulse.retention_days', 7);
        if (!is_numeric($days) || (int) $days < 1) {
            $this->error('The retention must be a whole number of days, at least 1.');

            return self::FAILURE;
        }

        $deleted = $pulse->prune((int) $days, (bool) $this->option('vacuum'));

        $this->info(sprintf('Pruned %d row(s) older than %d day(s).', array_sum($deleted), (int) $days));
        foreach ($deleted as $table => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %s: %d', $table, $count));
            }
        }

        return self::SUCCESS;
    }
}
