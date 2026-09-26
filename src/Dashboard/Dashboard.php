<?php

declare(strict_types=1);

namespace PulsePHP\Dashboard;

use Closure;
use InvalidArgumentException;
use PulsePHP\Storage\QueryEngine;

final class Dashboard
{
    private QueryEngine $queries;
    private ?array $basicAuth = null;
    private ?array $allowedIps = null;
    private ?Closure $authorizationCallback = null;
    private bool $authorizationConfigured = false;

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

        $stats = $this->queries->getSummaryStats();
        $timeline = $this->queries->getRequestTimeline();
        $slowQueries = $this->queries->getSlowestQueries();
        $topSpans = $this->queries->getTopSpans();
        $exceptions = $this->queries->getLatestExceptions();

        $timelineLabels = json_encode(
            array_map(static fn (array $point): string => $point['minute'], $timeline),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );
        $timelineValues = json_encode(
            array_map(static fn (array $point): int => $point['requests'], $timeline),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
        );

        $slowQueryRows = $this->renderSlowQueryRows($slowQueries);
        $spanRows = $this->renderSpanRows($topSpans);
        $exceptionRows = $this->renderExceptionRows($exceptions);

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, private');
        }

        $requestCount = number_format($stats['requests_total']);
        $averageDuration = number_format($stats['avg_response_ms'], 2);
        $peakMemory = number_format($stats['peak_memory_bytes'] / 1_048_576, 1);
        $exceptionCount = number_format($stats['exceptions_today']);

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>PulsePHP · Observability</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: light; }
        body { font-family: 'DM Sans', sans-serif; background: #f3f5f2; color: #18211d; }
        .mono { font-family: 'DM Mono', monospace; }
        .panel { border: 1px solid #dce3de; border-radius: 8px; background: #fff; }
        .eyebrow { letter-spacing: .08em; }
        .scroll-table { scrollbar-width: thin; scrollbar-color: #cbd5cf transparent; }
        @media (prefers-reduced-motion: no-preference) {
            main { animation: enter .35s ease-out both; }
            @keyframes enter { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        }
    </style>
</head>
<body class="min-h-screen">
    <header class="border-b border-[#dce3de] bg-white">
        <div class="mx-auto flex max-w-[1440px] items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#123b32] text-sm font-bold text-[#aaf0c8]">P</span>
                <div>
                    <p class="text-sm font-bold leading-tight">Pulse<span class="font-normal text-[#617169]">PHP</span></p>
                    <p class="eyebrow mt-1 text-[10px] font-semibold uppercase text-[#718078]">Local observability</p>
                </div>
            </div>
            <div class="flex items-center gap-2 text-xs font-medium text-[#52645a]">
                <span class="h-2 w-2 rounded-full bg-[#1b9a68]"></span>
                SQLite connected
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-[1440px] px-4 py-7 sm:px-6 lg:px-8 lg:py-9">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow text-[11px] font-semibold uppercase text-[#16805a]">Runtime overview</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-[28px]">Application pulse</h1>
                <p class="mt-1 text-sm text-[#68776f]">Request and exception data from your local SQLite store.</p>
            </div>
            <span class="rounded-md border border-[#dce3de] bg-white px-3 py-2 text-xs font-medium text-[#52645a]">Last 60 minutes</span>
        </div>

        <section aria-label="Summary statistics" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Total requests <span class="ml-1 text-[10px] uppercase text-[#9aa69f]">all time</span></p>
                <p class="mono mt-3 text-[27px] font-medium leading-none">{$requestCount}</p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Average response</p>
                <p class="mono mt-3 text-[27px] font-medium leading-none">{$averageDuration}<span class="ml-1 text-sm text-[#718078]">ms</span></p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Peak request memory</p>
                <p class="mono mt-3 text-[27px] font-medium leading-none">{$peakMemory}<span class="ml-1 text-sm text-[#718078]">MB</span></p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Exceptions <span class="ml-1 text-[10px] uppercase text-[#9aa69f]">today</span></p>
                <p class="mono mt-3 text-[27px] font-medium leading-none">{$exceptionCount}</p>
            </article>
        </section>

        <section class="panel mt-4 p-4 sm:p-5" aria-labelledby="traffic-title">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 id="traffic-title" class="text-sm font-semibold">Request activity</h2>
                    <p class="mt-1 text-xs text-[#718078]">Requests recorded per minute</p>
                </div>
                <span class="mono text-xs text-[#718078]">60 min</span>
            </div>
            <div class="h-[230px] sm:h-[270px]">
                <canvas id="requests-chart" aria-label="Requests per minute over the last hour" role="img"></canvas>
            </div>
        </section>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <section class="panel min-w-0" aria-labelledby="queries-title">
                <div class="flex items-start justify-between border-b border-[#e8ede9] px-4 py-4 sm:px-5">
                    <div>
                        <h2 id="queries-title" class="text-sm font-semibold">Slowest query patterns</h2>
                        <p class="mt-1 text-xs text-[#718078]">Grouped by normalized SQL</p>
                    </div>
                    <span class="eyebrow rounded bg-[#f0f5f1] px-2 py-1 text-[10px] font-semibold uppercase text-[#557065]">Top 10</span>
                </div>
                <div class="scroll-table overflow-x-auto">
                    <table class="w-full min-w-[600px] text-left text-xs">
                        <thead class="bg-[#f8faf8] text-[10px] uppercase text-[#77857d]">
                            <tr><th class="px-4 py-3 font-semibold sm:px-5">Normalized SQL</th><th class="px-3 py-3 text-right font-semibold">Avg</th><th class="px-3 py-3 text-right font-semibold">Runs</th><th class="px-4 py-3 text-right font-semibold sm:px-5">Peak</th></tr>
                        </thead>
                        <tbody class="divide-y divide-[#edf0ee]">{$slowQueryRows}</tbody>
                    </table>
                </div>
            </section>

            <section class="panel min-w-0" aria-labelledby="spans-title">
                <div class="flex items-start justify-between border-b border-[#e8ede9] px-4 py-4 sm:px-5">
                    <div>
                        <h2 id="spans-title" class="text-sm font-semibold">Slowest spans</h2>
                        <p class="mt-1 text-xs text-[#718078]">Timers created with pulse_start() / pulse_end()</p>
                    </div>
                    <span class="eyebrow rounded bg-[#f0f5f1] px-2 py-1 text-[10px] font-semibold uppercase text-[#557065]">Top 10</span>
                </div>
                <div class="scroll-table overflow-x-auto">
                    <table class="w-full min-w-[480px] text-left text-xs">
                        <thead class="bg-[#f8faf8] text-[10px] uppercase text-[#77857d]">
                            <tr><th class="px-4 py-3 font-semibold sm:px-5">Span</th><th class="px-3 py-3 text-right font-semibold">Avg</th><th class="px-3 py-3 text-right font-semibold">Runs</th><th class="px-4 py-3 text-right font-semibold sm:px-5">Peak</th></tr>
                        </thead>
                        <tbody class="divide-y divide-[#edf0ee]">{$spanRows}</tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="panel mt-4" aria-labelledby="exceptions-title">
            <div class="flex items-start justify-between border-b border-[#e8ede9] px-4 py-4 sm:px-5">
                <div>
                    <h2 id="exceptions-title" class="text-sm font-semibold">Recent exceptions</h2>
                    <p class="mt-1 text-xs text-[#718078]">Latest captured errors and uncaught exceptions</p>
                </div>
                <span class="eyebrow rounded bg-[#fff2ed] px-2 py-1 text-[10px] font-semibold uppercase text-[#b64d30]">Latest 10</span>
            </div>
            <div class="scroll-table overflow-x-auto">
                <table class="w-full min-w-[680px] text-left text-xs">
                    <thead class="bg-[#f8faf8] text-[10px] uppercase text-[#77857d]">
                        <tr><th class="px-4 py-3 font-semibold sm:px-5">Message</th><th class="px-3 py-3 font-semibold">Location</th><th class="px-4 py-3 text-right font-semibold sm:px-5">Recorded</th></tr>
                    </thead>
                    <tbody class="divide-y divide-[#edf0ee]">{$exceptionRows}</tbody>
                </table>
            </div>
        </section>

        <footer class="pb-4 pt-5 text-center text-[11px] text-[#89968f]">PulsePHP · local telemetry</footer>
    </main>

    <script>
        const timelineLabels = {$timelineLabels};
        const timelineValues = {$timelineValues};
        const canvas = document.getElementById('requests-chart');
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: timelineLabels,
                datasets: [{
                    data: timelineValues,
                    borderColor: '#16805a',
                    backgroundColor: 'rgba(22, 128, 90, 0.09)',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: '#16805a',
                    fill: true,
                    tension: 0.32
                }]
            },
            options: {
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { title: items => items.length ? timelineLabels[items[0].dataIndex] : '' } }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            maxTicksLimit: 8,
                            color: '#89968f',
                            font: { family: 'DM Mono', size: 10 },
                            callback: (index) => timelineLabels[index].slice(11, 16)
                        }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false, dash: [3, 4] },
                        grid: { color: '#edf1ee' },
                        ticks: { precision: 0, color: '#89968f', font: { family: 'DM Mono', size: 10 } }
                    }
                }
            }
        });
    </script>
</body>
</html>
HTML;
    }

    private function renderSlowQueryRows(array $queries): string
    {
        if ($queries === []) {
            return $this->emptyRow(4, 'No queries recorded yet.');
        }

        $rows = '';
        foreach ($queries as $query) {
            $rows .= '<tr class="align-top">'
                . '<td class="max-w-[360px] px-4 py-3 sm:px-5"><code class="mono block max-h-14 overflow-hidden break-words text-[11px] leading-5 text-[#355448]">'
                . $this->escape($query['normalized_sql']) . '</code></td>'
                . '<td class="mono whitespace-nowrap px-3 py-3 text-right">'
                . number_format($query['avg_duration_ms'], 2) . ' ms</td>'
                . '<td class="mono px-3 py-3 text-right">' . number_format($query['executions']) . '</td>'
                . '<td class="mono whitespace-nowrap px-4 py-3 text-right sm:px-5">'
                . number_format($query['max_duration_ms'], 2) . ' ms</td></tr>';
        }

        return $rows;
    }

    private function renderSpanRows(array $spans): string
    {
        if ($spans === []) {
            return $this->emptyRow(4, 'No timed spans recorded yet.');
        }

        $rows = '';
        foreach ($spans as $span) {
            $rows .= '<tr>'
                . '<td class="max-w-[240px] break-words px-4 py-3 font-medium text-[#355448] sm:px-5">'
                . $this->escape($span['name']) . '</td>'
                . '<td class="mono whitespace-nowrap px-3 py-3 text-right">'
                . number_format($span['avg_duration_ms'], 2) . ' ms</td>'
                . '<td class="mono px-3 py-3 text-right">' . number_format($span['executions']) . '</td>'
                . '<td class="mono whitespace-nowrap px-4 py-3 text-right sm:px-5">'
                . number_format($span['max_duration_ms'], 2) . ' ms</td></tr>';
        }

        return $rows;
    }

    private function renderExceptionRows(array $exceptions): string
    {
        if ($exceptions === []) {
            return $this->emptyRow(3, 'No exceptions recorded yet.');
        }

        $rows = '';
        foreach ($exceptions as $exception) {
            $location = $this->escape($exception['file']) . ':' . $exception['line'];
            $rows .= '<tr class="align-top">'
                . '<td class="max-w-[520px] break-words px-4 py-3 text-[#9d482e] sm:px-5">'
                . $this->escape($exception['message']) . '</td>'
                . '<td class="mono max-w-[280px] break-all px-3 py-3 text-[11px] text-[#66776e]">'
                . $location . '</td>'
                . '<td class="mono whitespace-nowrap px-4 py-3 text-right text-[#66776e] sm:px-5">'
                . $this->escape($exception['created_at']) . '</td></tr>';
        }

        return $rows;
    }

    private function emptyRow(int $columns, string $message): string
    {
        return '<tr><td colspan="' . $columns . '" class="px-4 py-8 text-center text-xs text-[#89968f]">'
            . $this->escape($message) . '</td></tr>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            } catch (\Throwable) {
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