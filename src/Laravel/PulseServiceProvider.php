<?php

declare(strict_types=1);

namespace PulsePHP\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PulsePHP\Laravel\Collectors\OutboundHttpCollector;
use PulsePHP\Laravel\Console\CheckCommand;
use PulsePHP\Laravel\Console\DeployCommand;
use PulsePHP\Laravel\Console\InstallCommand;
use PulsePHP\Laravel\Console\PruneCommand;
use PulsePHP\Laravel\Http\Controllers\DashboardController;
use PulsePHP\Laravel\Http\Middleware\PulseMiddleware;
use PulsePHP\Pulse;
use Throwable;

final class PulseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/pulse.php';
        $this->mergeConfigFrom($configPath, 'pulse');

        $this->app->singleton(Pulse::class, function ($app): Pulse {
            $config = $app['config'];

            return Pulse::init(
                (string) $config->get('pulse.database_path'),
                false,
                (string) $config->get('pulse.service_name', 'default'),
                [
                    'sample_rate' => (float) $config->get('pulse.sample_rate', 1.0),
                    'ignore_paths' => $this->ignoredPaths(),
                    'max_buffer_size' => (int) $config->get('pulse.max_buffer_size', 1000),
                    'retention_days' => (int) $config->get('pulse.retention_days', 7),
                    'prune_lottery' => (array) $config->get('pulse.prune_lottery', [1, 200]),
                ]
            )->reportErrorsUsing(static function (string $message, Throwable $exception): void {
                // Logged, never re-thrown: telemetry must not break the app.
                Log::warning('[PulsePHP] ' . $message, ['error' => $exception->getMessage()]);
            });
        });
    }

    public function boot(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/pulse.php';
        $this->publishes([$configPath => config_path('pulse.php')], 'pulse-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneCommand::class, CheckCommand::class, InstallCommand::class, DeployCommand::class]);
        }

        $config = $this->app['config'];
        $this->registerDashboardRoutes();

        if (!$config->get('pulse.enabled', true)) {
            return;
        }

        if ($config->get('pulse.collect_requests', true)
            || $config->get('pulse.collect_queries', true)
            || $config->get('pulse.collect_outbound_requests', true)) {
            $this->registerMiddleware(['web', 'api']);
        }

        if ($config->get('pulse.collect_exceptions', true)) {
            $this->registerExceptionReporting();
        }

        if ($config->get('pulse.collect_queries', true)) {
            DB::listen(function (QueryExecuted $query): void {
                $this->app->make(Pulse::class)->recordQuery($query->sql, (float) $query->time);
            });
        }

        if ($config->get('pulse.collect_outbound_requests', true)) {
            $outboundCollector = new OutboundHttpCollector(fn (): Pulse => $this->app->make(Pulse::class));
            Event::listen(RequestSending::class, [$outboundCollector, 'requestSending']);
            Event::listen(ResponseReceived::class, [$outboundCollector, 'responseReceived']);
            Event::listen(ConnectionFailed::class, [$outboundCollector, 'connectionFailed']);
        }

        if ($config->get('pulse.collect_jobs', true)) {
            $this->registerQueueHooks();
        }

        if ($config->get('pulse.collect_logs', true)) {
            $this->registerLogListener((string) $config->get('pulse.log_level', 'warning'));
        }

        // Long-running runtimes never reach PHP shutdown between units of
        // work, so the buffer is written when each request terminates.
        $this->app->terminating(function (): void {
            if ($this->app->resolved(Pulse::class)) {
                $this->app->make(Pulse::class)->flush();
            }
        });
        Event::listen('Laravel\Octane\Events\RequestTerminated', function (): void {
            if ($this->app->resolved(Pulse::class)) {
                $this->app->make(Pulse::class)->flush();
            }
        });
    }

    /**
     * Adds the middleware through the HTTP kernel when possible: the kernel
     * re-syncs its groups to the router, which would silently drop entries
     * pushed onto the router alone (Laravel 11+ application bootstrapping).
     *
     * @param list<string> $groups
     */
    private function registerMiddleware(array $groups): void
    {
        $kernel = $this->app->make(HttpKernel::class);
        $kernelGroups = method_exists($kernel, 'getMiddlewareGroups') ? $kernel->getMiddlewareGroups() : null;

        foreach ($groups as $group) {
            if (is_array($kernelGroups) && method_exists($kernel, 'appendMiddlewareToGroup')) {
                if (isset($kernelGroups[$group])) {
                    $kernel->appendMiddlewareToGroup($group, PulseMiddleware::class);
                }

                continue;
            }

            $this->app['router']->pushMiddlewareToGroup($group, PulseMiddleware::class);
        }
    }

    private function registerDashboardRoutes(): void
    {
        $config = $this->app['config'];
        $middleware = (array) $config->get('pulse.dashboard_middleware', ['web']);
        $path = $this->dashboardPath();

        Route::middleware($middleware)->group(function () use ($path): void {
            Route::get($path, [DashboardController::class, 'show'])->name('pulse.dashboard');
            Route::post($path, [DashboardController::class, 'show'])->name('pulse.action');
            Route::get($path . '/data', [DashboardController::class, 'data'])->name('pulse.data');
        });
    }

    /**
     * Laravel renders exceptions inside the routing pipeline, so middleware
     * never sees them. Hooking the exception handler captures every reported
     * exception — HTTP, queued jobs and console — and honours `dontReport`.
     */
    private function registerExceptionReporting(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (!method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $exception): void {
                try {
                    $this->app->make(Pulse::class)->recordException($exception, true);
                } catch (Throwable) {
                    // Never interfere with the application's own reporting.
                }
            });
        });
    }

    private function registerQueueHooks(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $pulse = $this->app->make(Pulse::class);
            $name = 'job:' . $event->job->resolveName();
            $pulse->beginTrace($name, 'JOB', $name);
            $pulse->startTimer($name);
        });

        $finish = function (JobProcessed|JobFailed|JobExceptionOccurred $event): void {
            $pulse = $this->app->make(Pulse::class);
            $pulse->endTimer('job:' . $event->job->resolveName());
            $pulse->writeBuffer();
        };

        Event::listen(JobProcessed::class, $finish);
        Event::listen(JobFailed::class, $finish);
        Event::listen(JobExceptionOccurred::class, $finish);
    }

    /**
     * Stores log lines at or above $minimumLevel. Lines that carry an
     * exception are skipped: the exception itself is already recorded.
     */
    private function registerLogListener(string $minimumLevel): void
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        $threshold = array_search(strtolower($minimumLevel), $levels, true);
        $threshold = $threshold === false ? 3 : $threshold;

        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels, $threshold): void {
            $level = array_search(strtolower($event->level), $levels, true);
            if ($level === false || $level < $threshold
                || ($event->context['exception'] ?? null) instanceof Throwable
                || str_starts_with((string) $event->message, '[PulsePHP]')) {
                return;
            }

            try {
                $this->app->make(Pulse::class)->recordLog($event->level, (string) $event->message, $event->context);
            } catch (Throwable) {
                // Logging must never fail because of telemetry.
            }
        });
    }

    /** @return list<string> */
    private function ignoredPaths(): array
    {
        $dashboard = '/' . $this->dashboardPath();

        return array_values(array_unique(array_merge(
            array_map('strval', (array) $this->app['config']->get('pulse.ignore_paths', [])),
            [$dashboard, $dashboard . '/*']
        )));
    }

    private function dashboardPath(): string
    {
        $path = trim((string) $this->app['config']->get('pulse.dashboard_path', 'pulse'), '/');

        return $path === '' ? 'pulse' : $path;
    }
}
