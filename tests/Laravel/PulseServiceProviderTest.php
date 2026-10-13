<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Laravel;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use PDO;
use PulsePHP\Laravel\PulseServiceProvider;
use PulsePHP\Pulse;
use ReflectionProperty;
use RuntimeException;

final class PulseServiceProviderTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
        $path = tempnam(sys_get_temp_dir(), 'pulse-laravel-');
        self::assertNotFalse($path);
        unlink($path);
        $this->dbPath = $path . '.sqlite';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
        foreach (glob($this->dbPath . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [PulseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('pulse.database_path', $this->dbPath);
        $app['config']->set('pulse.service_name', 'shop');
        $app['config']->set('pulse.prune_lottery', [0, 1]);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(static function ($router): void {
            $router->get('/orders', static function () {
                foreach (range(1, 6) as $id) {
                    DB::select('select ? as id', [$id]);
                }

                return 'ok';
            })->name('orders.index');

            $router->get('/boom', static function (): never {
                throw new RuntimeException('checkout exploded');
            })->name('checkout');

            $router->get('/missing', static fn () => abort(404))->name('missing');

            $router->get('/stock', static function () {
                Log::warning('Stock is low', ['sku' => 'A-1']);
                Log::info('Just chatter');

                return 'ok';
            })->name('stock');
        });
    }

    public function test_requests_are_recorded_with_route_context_and_without_query_strings(): void
    {
        $this->get('/orders?token=secret')->assertOk();

        $request = $this->telemetry()->query('SELECT * FROM pulse_requests')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('orders.index', $request['route']);
        self::assertSame('shop', $request['service']);
        self::assertSame(200, (int) $request['status_code']);
        self::assertStringEndsWith('/orders', $request['url']);
        self::assertStringNotContainsString('secret', $request['url']);
        self::assertSame(16, strlen($request['trace_id']));

        $queries = $this->telemetry()->prepare('SELECT COUNT(*) FROM pulse_queries WHERE trace_id = ?');
        $queries->execute([$request['trace_id']]);
        self::assertSame(6, (int) $queries->fetchColumn());
    }

    public function test_exceptions_rendered_by_laravel_are_captured(): void
    {
        $this->get('/boom')->assertStatus(500);

        $exception = $this->telemetry()->query('SELECT * FROM pulse_exceptions')->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($exception, 'Exceptions handled by the framework must still be recorded.');
        self::assertSame(RuntimeException::class, $exception['class']);
        self::assertSame('checkout exploded', $exception['message']);
        self::assertSame('checkout', $exception['route']);
        self::assertSame(1, (int) $exception['unhandled']);

        $request = $this->telemetry()->query('SELECT status_code, trace_id FROM pulse_requests')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(500, (int) $request['status_code']);
        self::assertSame($exception['trace_id'], $request['trace_id']);
    }

    public function test_exceptions_laravel_does_not_report_are_ignored(): void
    {
        $this->get('/missing')->assertNotFound();

        self::assertSame(0, (int) $this->telemetry()->query('SELECT COUNT(*) FROM pulse_exceptions')->fetchColumn());
    }

    public function test_the_dashboard_is_denied_until_the_gate_allows_it(): void
    {
        $this->get('/pulse')->assertForbidden();
        $this->getJson('/pulse/data')->assertForbidden();
    }

    public function test_the_dashboard_and_its_live_endpoint_work_and_are_not_recorded(): void
    {
        Gate::define('viewPulse', static fn (?object $user = null): bool => true);
        $this->get('/orders')->assertOk();

        $page = $this->get('/pulse')->assertOk();
        self::assertStringContainsString('const pulseDataEndpoint = "/pulse/data";', (string) $page->getContent());

        $this->getJson('/pulse/data?period=1h')
            ->assertOk()
            ->assertJsonPath('stats.requests_total', 1)
            ->assertJsonStructure(['stats', 'chartData', 'routeCards', 'exceptions', 'exceptionGroups']);

        $routes = $this->telemetry()->query('SELECT route FROM pulse_requests')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['orders.index'], $routes, 'Dashboard traffic must not pollute the metrics.');
    }

    public function test_queued_jobs_get_their_own_trace_and_are_flushed(): void
    {
        RecordsLines::dispatch();

        $span = $this->telemetry()->query("SELECT name, trace_id FROM pulse_spans WHERE name LIKE 'job:%'")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($span, 'Jobs are written as soon as they finish.');
        self::assertSame('job:' . RecordsLines::class, $span['name']);

        $queries = $this->telemetry()->prepare('SELECT route FROM pulse_queries WHERE trace_id = ?');
        $queries->execute([$span['trace_id']]);
        self::assertSame(
            array_fill(0, 3, 'job:' . RecordsLines::class),
            $queries->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function test_the_prune_command_applies_the_retention(): void
    {
        $this->get('/orders')->assertOk();
        $this->telemetry()->exec("UPDATE pulse_requests SET created_at = datetime('now', '-30 days')");

        $this->artisan('pulse:prune', ['--days' => 7])
            ->expectsOutputToContain('older than 7 day(s)')
            ->assertSuccessful();

        self::assertSame(0, (int) $this->telemetry()->query('SELECT COUNT(*) FROM pulse_requests')->fetchColumn());
    }

    public function test_the_check_command_is_a_no_op_while_alerts_are_disabled(): void
    {
        $this->artisan('pulse:check')
            ->expectsOutputToContain('Alerts are disabled')
            ->assertSuccessful();
    }

    public function test_warnings_are_stored_as_logs_linked_to_the_request(): void
    {
        $this->get('/stock')->assertOk();

        $logs = $this->telemetry()->query('SELECT level, message, route, trace_id FROM pulse_logs')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $logs, 'Info is below the default log_level.');
        self::assertSame('warning', $logs[0]['level']);
        self::assertSame('stock', $logs[0]['route']);
        $request = $this->telemetry()->query('SELECT trace_id FROM pulse_requests')->fetchColumn();
        self::assertSame($request, $logs[0]['trace_id']);
    }

    public function test_an_error_group_can_be_resolved_from_the_dashboard(): void
    {
        Gate::define('viewPulse', static fn (?object $user = null): bool => true);
        $this->get('/boom')->assertStatus(500);
        $fingerprint = $this->telemetry()->query('SELECT fingerprint FROM pulse_exceptions')->fetchColumn();

        $this->get('/pulse?view=error&id=' . urlencode($fingerprint))
            ->assertOk()
            ->assertSee('Mark as resolved')
            ->assertSee('name="_token"', false);

        $this->post('/pulse?view=error&id=' . urlencode($fingerprint), [
            'action' => 'resolve',
            'fingerprint' => $fingerprint,
        ])->assertStatus(303)->assertHeader('Location');

        self::assertSame('resolved', $this->telemetry()->query('SELECT status FROM pulse_exception_states')->fetchColumn());
    }

    public function test_the_deploy_command_marks_a_release(): void
    {
        $this->artisan('pulse:deploy', ['version' => 'v3.1.0'])
            ->expectsOutputToContain('Marked deploy v3.1.0')
            ->assertSuccessful();

        self::assertSame('v3.1.0', $this->telemetry()->query('SELECT version FROM pulse_deploys')->fetchColumn());
    }

    public function test_every_dashboard_screen_renders_inside_laravel(): void
    {
        Gate::define('viewPulse', static fn (?object $user = null): bool => true);
        $this->get('/orders')->assertOk();

        foreach (['overview', 'requests', 'errors', 'queries', 'jobs', 'outbound', 'logs', 'settings'] as $view) {
            $this->get('/pulse?view=' . $view)->assertOk();
        }
        $this->get('/pulse?asset=app.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
    }

    private function telemetry(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}

final class RecordsLines implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
        foreach (range(1, 3) as $line) {
            DB::select('select ? as line', [$line]);
        }
    }
}
