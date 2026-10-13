<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'pulse:install';

    protected $description = 'Publish the PulsePHP configuration and show the remaining setup steps';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'pulse-config']);

        $path = '/' . trim((string) config('pulse.dashboard_path', 'pulse'), '/');

        $this->newLine();
        $this->info('PulsePHP installed. Remaining steps:');
        $this->newLine();
        $this->line('1. Allow dashboard access (it is denied by default), e.g. in AppServiceProvider::boot():');
        $this->line("   Gate::define('viewPulse', fn (\$user) => \$user->is_admin);");
        $this->newLine();
        $this->line('2. Schedule maintenance, e.g. in routes/console.php:');
        $this->line("   Schedule::command('pulse:prune')->daily();");
        $this->line("   Schedule::command('pulse:check')->everyMinute(); // when PULSE_ALERTS_ENABLED=true");
        $this->newLine();
        $this->line(sprintf('3. Open %s in your browser.', $path));

        return self::SUCCESS;
    }
}
