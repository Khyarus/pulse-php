<?php

declare(strict_types=1);

namespace PulsePHP\Laravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PulsePHP\Laravel\Http\Controllers\DashboardController;
use PulsePHP\Laravel\Http\Middleware\PulseMiddleware;
use PulsePHP\Pulse;

final class PulseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/pulse.php';
        $this->mergeConfigFrom($configPath, 'pulse');

        $this->app->singleton(Pulse::class, function ($app): Pulse {
            return Pulse::init((string) $app['config']->get('pulse.database_path'), false);
        });
    }

    public function boot(): void
    {
        $configPath = dirname(__DIR__, 2) . '/config/pulse.php';
        $this->publishes([$configPath => config_path('pulse.php')], 'pulse-config');

        if (!$this->app['config']->get('pulse.enabled', true)) {
            return;
        }

        $config = $this->app['config'];
        if ($config->get('pulse.collect_requests', true)
            || $config->get('pulse.collect_exceptions', true)) {
            $this->app['router']->pushMiddlewareToGroup('web', PulseMiddleware::class);
        }

        if ($config->get('pulse.collect_queries', true)) {
            DB::listen(static function (QueryExecuted $query): void {
                app(Pulse::class)->recordQuery($query->sql, (float) $query->time);
            });
        }

        $dashboardPath = trim((string) $config->get('pulse.dashboard_path', 'pulse'), '/');
        $dashboardPath = $dashboardPath === '' ? 'pulse' : $dashboardPath;
        $middleware = (array) $config->get('pulse.dashboard_middleware', ['web']);

        Route::middleware($middleware)
            ->get($dashboardPath, DashboardController::class)
            ->name('pulse.dashboard');
    }
}