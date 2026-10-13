<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use PulsePHP\Dashboard\Dashboard;
use PulsePHP\Dashboard\TraceWaterfall;
use PulsePHP\Pulse;
use PulsePHP\Storage\QueryEngine;
use PulsePHP\Storage\SQLiteStorage;
use ReflectionProperty;
use RuntimeException;

final class DashboardV2Test extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
        $path = tempnam(sys_get_temp_dir(), 'pulse-v2-');
        self::assertNotFalse($path);
        unlink($path);
        $this->dbPath = $path . '.sqlite';
        $_GET = $_POST = $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
        $_GET = $_POST = $_COOKIE = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        foreach (glob($this->dbPath . '*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function test_resolving_an_error_hides_it_until_it_regresses(): void
    {
        $storage = new SQLiteStorage($this->dbPath);
        $storage->writeBatch(['exceptions' => [$this->exception('first')]]);
        $fingerprint = (new QueryEngine($this->dbPath))->getExceptionGroups()[0]['fingerprint'];

        $page = $this->dashboard()->handle();
        preg_match('/pulse_csrf=([a-f0-9]{64})/', $page->headers()['Set-Cookie'] ?? '', $cookie);
        self::assertNotEmpty($cookie, 'The dashboard issues its own CSRF cookie.');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'resolve', 'fingerprint' => $fingerprint, 'pulse_csrf' => 'forged'];
        $_COOKIE = ['pulse_csrf' => $cookie[1]];
        self::assertSame(419, $this->dashboard()->handle()->status, 'A forged token is rejected.');

        $_POST['pulse_csrf'] = $cookie[1];
        $resolved = $this->dashboard()->handle();
        self::assertSame(303, $resolved->status);
        self::assertStringStartsWith('?view=error&id=', $resolved->headers()['Location']);

        $queries = new QueryEngine($this->dbPath);
        self::assertSame([], $queries->getExceptionGroups(10, 'all', null, null, 'open'));
        self::assertSame('resolved', $queries->getExceptionGroups(10, 'all', null, null, 'resolved')[0]['status']);

        // Happens again after being resolved.
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec("UPDATE pulse_exception_states SET updated_at = datetime('now', '-1 hour')");
        $storage->writeBatch(['exceptions' => [$this->exception('again')]]);

        $open = $queries->getExceptionGroups(10, 'all', null, null, 'open');
        self::assertCount(1, $open);
        self::assertSame('regressed', $open[0]['status']);
    }

    public function test_ignored_errors_stay_out_of_the_open_list(): void
    {
        $storage = new SQLiteStorage($this->dbPath);
        $storage->writeBatch(['exceptions' => [$this->exception('noise')]]);
        $queries = new QueryEngine($this->dbPath);
        $storage->setExceptionStatus($queries->getExceptionGroups()[0]['fingerprint'], 'ignored');
        $storage->writeBatch(['exceptions' => [$this->exception('more noise')]]);

        self::assertSame([], $queries->getExceptionGroups(10, 'all', null, null, 'open'));
        self::assertCount(1, $queries->getExceptionGroups(10, 'all', null, null, 'ignored'));
    }

    public function test_logs_deploys_and_timeline_offsets_are_recorded(): void
    {
        $pulse = Pulse::init($this->dbPath, false, 'shop');
        $pulse->deploy('v2.0.0');
        $traceId = $pulse->beginTrace('checkout', 'POST', '/checkout');
        $pulse->startTimer('cart.load');
        usleep(2000);
        $pulse->endTimer('cart.load');
        $pulse->recordQuery('SELECT * FROM carts WHERE id = 1', 1.5);
        $pulse->recordLog('warning', 'Stock is low', ['sku' => 'A-1', 'user' => new \stdClass(), 'error' => new RuntimeException('x')]);
        $pulse->recordRequest('/checkout', 'POST', 201, 12.0, 0);
        $pulse->flush();

        $queries = new QueryEngine($this->dbPath);
        self::assertSame('v2.0.0', $queries->getDeploys('1h')[0]['version']);

        $log = $queries->getLogs(10, '1h')[0];
        self::assertSame('warning', $log['level']);
        self::assertSame(
            ['sku' => 'A-1', 'user' => '[stdClass]', 'error' => 'RuntimeException: x'],
            json_decode($log['context'], true)
        );

        $trace = $queries->getTrace($traceId);
        self::assertNotNull($trace);
        self::assertCount(1, $trace['logs']);
        self::assertNotNull($trace['spans'][0]['offset_ms']);
        self::assertGreaterThan((float) $trace['spans'][0]['offset_ms'], (float) $trace['queries'][0]['offset_ms']);

        $waterfall = TraceWaterfall::build($trace);
        self::assertSame(['request', 'span', 'query'], array_column($waterfall['rows'], 'kind'));
        self::assertFalse($waterfall['rows'][1]['estimated']);
        foreach ($waterfall['rows'] as $row) {
            self::assertLessThanOrEqual(100.0, $row['left'] + $row['width']);
        }
    }

    public function test_request_search_filters_and_paginates(): void
    {
        $rows = [];
        foreach (range(1, 60) as $index) {
            $rows[] = [
                'url' => $index % 2 === 0 ? '/orders/' . $index : '/health',
                'method' => $index % 3 === 0 ? 'POST' : 'GET',
                'status_code' => $index % 10 === 0 ? 503 : 200,
                'duration_ms' => (float) $index * 10,
                'memory_bytes' => 0,
                'ip' => null,
            ];
        }
        (new SQLiteStorage($this->dbPath))->writeBatch(['requests' => $rows]);
        $queries = new QueryEngine($this->dbPath);

        self::assertSame(60, $queries->searchRequests(['period' => '1h'], 1, 50)['total']);
        self::assertCount(10, $queries->searchRequests(['period' => '1h'], 2, 50)['rows']);
        self::assertSame(6, $queries->searchRequests(['period' => '1h', 'status' => '5xx'])['total']);
        self::assertSame(30, $queries->searchRequests(['period' => '1h', 'search' => 'orders'])['total']);
        self::assertSame(20, $queries->searchRequests(['period' => '1h', 'method' => 'post'])['total']);
        self::assertSame(11, $queries->searchRequests(['period' => '1h', 'min_ms' => 500])['total']);
        self::assertSame(0, $queries->searchRequests(['period' => '1h', 'search' => '%'])['total'], 'LIKE wildcards are escaped.');
    }

    public function test_jobs_and_outbound_hosts_are_aggregated(): void
    {
        (new SQLiteStorage($this->dbPath))->writeBatch([
            'spans' => [
                ['name' => 'job:App\\Jobs\\SendInvoice', 'duration_ms' => 100.0, 'memory_bytes' => 0, 'trace_id' => 'aaaa000000000001'],
                ['name' => 'job:App\\Jobs\\SendInvoice', 'duration_ms' => 300.0, 'memory_bytes' => 0, 'trace_id' => 'aaaa000000000002'],
                ['name' => 'cache.get', 'duration_ms' => 1.0, 'memory_bytes' => 0],
            ],
            'exceptions' => [$this->exception('mail down') + ['trace_id' => 'aaaa000000000002']],
            'outbound_requests' => [
                ['method' => 'GET', 'status_code' => 200, 'url' => 'https://api.stripe.com/v1/charges', 'duration_ms' => 50.0],
                ['method' => 'GET', 'status_code' => 0, 'url' => 'https://api.stripe.com/v1/balance', 'duration_ms' => 150.0],
                ['method' => 'POST', 'status_code' => 201, 'url' => 'http://localhost:8080/hook', 'duration_ms' => 5.0],
            ],
        ]);
        $queries = new QueryEngine($this->dbPath);

        $jobs = $queries->getJobs('1h');
        self::assertCount(1, $jobs);
        self::assertSame('App\\Jobs\\SendInvoice', $jobs[0]['name']);
        self::assertSame(2, $jobs[0]['runs']);
        self::assertSame(1, $jobs[0]['failures']);
        self::assertSame(200.0, $jobs[0]['avg_ms']);

        $hosts = array_column($queries->getOutboundHosts('1h'), null, 'host');
        self::assertSame(2, $hosts['api.stripe.com']['calls']);
        self::assertSame(1, $hosts['api.stripe.com']['failures']);
        self::assertSame(1, $hosts['localhost:8080']['calls']);
    }

    public function test_the_overview_compares_with_the_previous_period(): void
    {
        $storage = new SQLiteStorage($this->dbPath);
        $request = ['url' => '/', 'method' => 'GET', 'status_code' => 200, 'duration_ms' => 10.0, 'memory_bytes' => 0, 'ip' => null];
        $storage->writeBatch(['requests' => array_fill(0, 5, $request)]);
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec("UPDATE pulse_requests SET created_at = datetime('now', '-90 minutes')");
        $storage->writeBatch(['requests' => array_fill(0, 30, $request)]);

        $vitals = $this->vitals();
        self::assertSame(30.0, (float) $vitals['requests']['value']);
        self::assertSame('Not enough data for the previous hour', $vitals['requests']['delta'], 'Five requests are too few to compare.');

        $storage->writeBatch(['requests' => array_fill(0, 15, $request)]);
        $pdo->exec("UPDATE pulse_requests SET created_at = datetime('now', '-90 minutes') WHERE id <= 20");

        $vitals = $this->vitals();
        self::assertSame(30.0, (float) $vitals['requests']['value']);
        self::assertSame('+50% vs previous hour', $vitals['requests']['delta']);
    }

    /** @return array<string, array<string, mixed>> */
    private function vitals(): array
    {
        $snapshot = json_decode($this->dashboard()->handleJson()->body, true);

        return array_column($snapshot['vitals'], null, 'key');
    }

    /** @return array<string, mixed> */
    private function exception(string $message): array
    {
        return ['class' => 'App\\PaymentFailed', 'message' => $message, 'file' => 'Pay.php', 'line' => 7, 'trace' => '#0 {main}'];
    }

    private function dashboard(): Dashboard
    {
        return (new Dashboard($this->dbPath))->authorize(static fn (): bool => true);
    }
}
