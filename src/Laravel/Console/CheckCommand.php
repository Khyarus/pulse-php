<?php

declare(strict_types=1);

namespace PulsePHP\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use PulsePHP\Alerts\AlertManager;

final class CheckCommand extends Command
{
    protected $signature = 'pulse:check {--dry-run : Show triggered alerts without notifying}';

    protected $description = 'Evaluate PulsePHP alert rules and notify the configured channels';

    public function handle(): int
    {
        $config = (array) config('pulse.alerts', []);
        if (!($config['enabled'] ?? false) && !$this->option('dry-run')) {
            $this->line('Alerts are disabled (PULSE_ALERTS_ENABLED=false).');

            return self::SUCCESS;
        }

        $service = (string) config('pulse.service_name', 'default');
        $manager = new AlertManager((string) config('pulse.database_path'), $config, $service);

        if ($this->option('dry-run')) {
            $alerts = $manager->evaluate();
            $this->reportAlerts($alerts, 'over threshold');

            return self::SUCCESS;
        }

        $webhook = $config['webhook_url'] ?? null;
        if (is_string($webhook) && $webhook !== '') {
            $manager->notifyWebhook($webhook, (string) ($config['webhook_format'] ?? 'json'));
        }

        $recipients = array_values(array_filter((array) ($config['mail_to'] ?? [])));
        if ($recipients !== []) {
            $manager->notifyUsing(static function (array $alerts, string $summary) use ($recipients, $service): void {
                Mail::raw($summary, static function ($message) use ($recipients, $service): void {
                    $message->to($recipients)->subject(sprintf('[PulsePHP] Alert on %s', $service));
                });
            });
        }

        $this->reportAlerts($manager->check(), 'sent');

        return self::SUCCESS;
    }

    /** @param list<array{rule: string, message: string}> $alerts */
    private function reportAlerts(array $alerts, string $verb): void
    {
        if ($alerts === []) {
            $this->info('No alerts.');

            return;
        }

        $this->warn(sprintf('%d alert(s) %s:', count($alerts), $verb));
        foreach ($alerts as $alert) {
            $this->line('  • ' . $alert['message']);
        }
    }
}
