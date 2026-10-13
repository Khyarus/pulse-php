<?php

declare(strict_types=1);

namespace PulsePHP\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use PulsePHP\Database\TracedPDO;
use PulsePHP\Http\GuzzleMiddleware;
use PulsePHP\Pulse;
use PulsePHP\Storage\SQLiteStorage;
use ReflectionProperty;

final class InstrumentationTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Pulse::class, 'instance'))->setValue(null, null);
    }

    public function test_traced_pdo_records_exec_query_and_prepared_statements(): void
    {
        $pulse = Pulse::init(':memory:', false);
        $pdo = new TracedPDO('sqlite::memory:');

        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
        $insert = $pdo->prepare('INSERT INTO users (email) VALUES (?)');
        $insert->execute(['first@example.test']);
        $insert->execute(['second@example.test']);
        $rows = array_column($pdo->query('SELECT email FROM users ORDER BY id', PDO::FETCH_ASSOC)->fetchAll(), 'email');
        $pulse->flush();

        self::assertSame(['first@example.test', 'second@example.test'], $rows);
        $recorded = $this->pulsePdo($pulse)->query(
            'SELECT catalog.normalized_sql, COUNT(*) FROM pulse_queries AS queries
             JOIN pulse_catalog AS catalog ON catalog.id = queries.catalog_id
             GROUP BY catalog.normalized_sql ORDER BY MIN(queries.id)'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertSame([
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)' => 1,
            'INSERT INTO users (email) VALUES (?)' => 2,
            'SELECT email FROM users ORDER BY id' => 1,
        ], array_map('intval', $recorded));
        self::assertStringNotContainsString('example.test', implode(' ', array_keys($recorded)));
    }

    public function test_guzzle_middleware_records_responses_and_connection_failures(): void
    {
        $pulse = Pulse::init(':memory:', false);
        $stack = HandlerStack::create(new MockHandler([
            new Response(201),
            new Response(503),
            new ConnectException('refused', new Request('GET', 'https://down.example.test/')),
        ]));
        $stack->push(GuzzleMiddleware::create(), 'pulse');
        $client = new Client(['handler' => $stack, 'http_errors' => true]);

        $client->post('https://user:secret@api.example.test/charges?token=hidden');
        foreach (['https://api.example.test/slow', 'https://down.example.test/'] as $url) {
            try {
                $client->get($url);
                self::fail('The request should have failed.');
            } catch (\GuzzleHttp\Exception\GuzzleException) {
                // Failures must still propagate to the caller.
            }
        }
        $pulse->flush();

        $calls = $this->pulsePdo($pulse)->query(
            'SELECT method, status_code, url FROM pulse_outbound_requests ORDER BY id'
        )->fetchAll(PDO::FETCH_NUM);

        self::assertSame([
            ['POST', 201, 'https://api.example.test/charges'],
            ['GET', 503, 'https://api.example.test/slow'],
            ['GET', 0, 'https://down.example.test/'],
        ], array_map(static fn (array $row): array => [$row[0], (int) $row[1], $row[2]], $calls));
    }

    private function pulsePdo(Pulse $pulse): PDO
    {
        $storage = (new ReflectionProperty(Pulse::class, 'storage'))->getValue($pulse);

        return (new ReflectionProperty(SQLiteStorage::class, 'pdo'))->getValue($storage);
    }
}
