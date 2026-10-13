<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Console;

use Illuminate\Console\Command;
use PulsePHP\Pulse;

final class DeployCommand extends Command
{
    protected $signature = 'pulse:deploy {version : Release name, tag or commit} {--service= : Defaults to pulse.service_name}';

    protected $description = 'Mark a deploy so it shows up on the PulsePHP charts';

    public function handle(Pulse $pulse): int
    {
        $version = trim((string) $this->argument('version'));
        if ($version === '') {
            $this->error('Give the deploy a version, e.g. php artisan pulse:deploy v1.4.0');

            return self::FAILURE;
        }

        $service = $this->option('service');
        $pulse->deploy($version, is_string($service) && $service !== '' ? $service : null);
        $this->info(sprintf('Marked deploy %s.', $version));

        return self::SUCCESS;
    }
}
