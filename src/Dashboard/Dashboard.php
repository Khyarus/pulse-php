<?php

declare(strict_types=1);

namespace PulsePHP\Dashboard;

use Closure;
use InvalidArgumentException;
use PulsePHP\Storage\QueryEngine;
use Throwable;

final class Dashboard
{
    private ?array $basicAuth = null;
    private ?array $allowedIps = null;
    private ?Closure $authorizationCallback = null;
    private bool $authorizationConfigured = false;
    private QueryEngine $queries;

    public function __construct(string $dbPath)
    {
        $this->queries = new QueryEngine($dbPath);
    }

    public function authWithBasic(string $username, string $password): self
    {
        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('Dashboard Basic Auth requires a username and password.');
        }

        $this->basicAuth = [
            'username' => hash('sha256', $username),
            'password' => hash('sha256', $password),
        ];
        $this->authorizationConfigured = true;

        return $this;
    }

    /** @param list<string> $ips */
    public function authWithIp(array $ips): self
    {
        $this->allowedIps = array_values(array_unique(array_filter(
            $ips,
            static fn (mixed $ip): bool => is_string($ip) && $ip !== ''
        )));
        $this->authorizationConfigured = true;

        return $this;
    }

    public function authorize(Closure $callback): self
    {
        $this->authorizationCallback = $callback;
        $this->authorizationConfigured = true;

        return $this;
    }

    public function render(): void
    {
        $denialStatus = $this->getDenialStatus();
        if ($denialStatus !== null) {
            $this->renderAccessDenied($denialStatus);

            return;
        }

        $periods = [
            '15m' => 'Last 15 minutes',
            '1h' => 'Last hour',
            '24h' => 'Last 24 hours',
            '7d' => 'Last 7 days',
        ];
        $period = $this->selectedPeriod($_GET['period'] ?? null, $periods);
        $services = $this->queries->getAvailableServices();
        $selectedService = $this->selectedOption($_GET['service'] ?? null, $services);
        $routes = $this->queries->getAvailableRoutes($selectedService);
        $selectedRoute = $this->selectedOption($_GET['route'] ?? null, $routes);

        $stats = $this->queries->getSummaryStats($period, $selectedService, $selectedRoute);
        $timeline = $this->queries->getRequestTimeline($period, $selectedService, $selectedRoute);
        $exceptionTimeline = $this->queries->getExceptionTimeline($period, $selectedService, $selectedRoute);
        $slowQueries = $this->queries->getSlowestQueries(100, $period, $selectedService, $selectedRoute);
        $spans = $this->queries->getTopSpans(100, $period, $selectedService, $selectedRoute);
        $outboundRequests = $this->queries->getOutboundRequests(100, $period, $selectedService, $selectedRoute);
        $requests = $this->queries->getRecentRequests(100, $period, $selectedService, $selectedRoute);
        $exceptions = $this->queries->getLatestExceptions(100, $period, $selectedService, $selectedRoute);

        $chartData = [
            'requests' => [
                'labels' => array_column($timeline, 'minute'),
                'values' => array_column($timeline, 'requests'),
            ],
            'queries' => [
                'labels' => array_map(
                    static fn (array $query): string => $query['normalized_sql'],
                    array_slice($slowQueries, 0, 10)
                ),
                'values' => array_map(
                    static fn (array $query): float => $query['avg_duration_ms'],
                    array_slice($slowQueries, 0, 10)
                ),
            ],
            'spans' => [
                'labels' => array_map(
                    static fn (array $span): string => $span['name'] . ' · ' . $span['service'],
                    array_slice($spans, 0, 10)
                ),
                'values' => array_map(
                    static fn (array $span): float => $span['avg_duration_ms'],
                    array_slice($spans, 0, 10)
                ),
            ],
            'outbound' => [
                'labels' => array_map(
                    static fn (array $request): string => $request['method'] . ' ' . $request['url'],
                    array_reverse(array_slice($outboundRequests, 0, 20))
                ),
                'values' => array_map(
                    static fn (array $request): float => $request['duration_ms'],
                    array_reverse(array_slice($outboundRequests, 0, 20))
                ),
            ],
            'exceptions' => [
                'labels' => array_column($exceptionTimeline, 'minute'),
                'values' => array_column($exceptionTimeline, 'exceptions'),
            ],
        ];
        $chartsJson = json_encode(
            $chartData,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, private');
        }

        $requestCount = number_format($stats['requests_total']);
        $averageDuration = number_format($stats['avg_response_ms'], 2);
        $peakMemory = number_format($stats['peak_memory_bytes'] / 1_048_576, 1);
        $exceptionCount = number_format($stats['exceptions_total']);
        $periodLabel = $periods[$period];

        require __DIR__ . '/resources/views/dashboard.php';
    }

    /** @param array<string, string> $periods */
    private function selectedPeriod(mixed $value, array $periods): string
    {
        return is_string($value) && isset($periods[$value]) ? $value : '1h';
    }

    /** @param list<string> $options */
    private function selectedOption(mixed $value, array $options): ?string
    {
        return is_string($value) && in_array($value, $options, true) ? $value : null;
    }

    private function getDenialStatus(): ?int
    {
        if (!$this->authorizationConfigured) {
            return 403;
        }

        if ($this->basicAuth !== null) {
            $credentials = $this->readBasicCredentials();
            if ($credentials === null) {
                return 401;
            }

            $usernameMatches = hash_equals($this->basicAuth['username'], hash('sha256', $credentials[0]));
            $passwordMatches = hash_equals($this->basicAuth['password'], hash('sha256', $credentials[1]));
            if (!$usernameMatches || !$passwordMatches) {
                return 401;
            }
        }

        if ($this->allowedIps !== null
            && !in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $this->allowedIps, true)) {
            return 403;
        }

        if ($this->authorizationCallback !== null) {
            try {
                if (!(($this->authorizationCallback)())) {
                    return 403;
                }
            } catch (Throwable) {
                return 403;
            }
        }

        return null;
    }

    /** @return array{string, string}|null */
    private function readBasicCredentials(): ?array
    {
        if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            return [(string) $_SERVER['PHP_AUTH_USER'], (string) $_SERVER['PHP_AUTH_PW']];
        }

        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');
        if (!preg_match('/^Basic\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        return explode(':', $decoded, 2);
    }

    private function renderAccessDenied(int $status): void
    {
        http_response_code($status);

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, private');
            if ($status === 401) {
                header('WWW-Authenticate: Basic realm="PulsePHP Dashboard", charset="UTF-8"');
            }
        }

        echo '<!doctype html><html lang="en"><meta charset="utf-8">'
            . '<title>Access denied</title><body><h1>Access denied</h1></body></html>';
    }
}
