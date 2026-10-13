<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$ms = static fn (mixed $value): string => number_format((float) $value, 2) . ' ms';
$request = $trace['request'] ?? null;
$totalQueryMs = $trace === null ? 0.0 : array_sum(array_map(
    static fn (array $query): float => (float) $query['duration_ms'],
    $trace['queries']
));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>PulsePHP · Trace</title>
    <style><?= $assets['css'] ?></style>
    <style>
        body { font-family: 'DM Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; background: #0b100e; color: #e8f0eb; }
        .mono { font-family: 'DM Mono', ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; }
        .panel { border: 1px solid #2a3631; border-radius: 8px; background: #121916; }
        th { color: #9aa9a1; font-weight: 600; }
        td, th { border-color: #2a3631; }
    </style>
</head>
<body class="min-h-screen">
    <main class="mx-auto max-w-[1200px] px-4 py-7 sm:px-6 lg:px-8">
        <a href="?" class="text-xs text-[#42c98a] underline underline-offset-2">← Back to dashboard</a>

        <?php if ($trace === null): ?>
            <section class="panel mt-5 p-8 text-center">
                <h1 class="text-lg font-semibold">Trace not found</h1>
                <p class="mt-2 text-sm text-[#9aa9a1]">It may have been pruned by the retention policy, or it was not sampled.</p>
            </section>
        <?php else: ?>
            <header class="mt-4">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-[#42c98a]">Trace</p>
                <h1 class="mono mt-1 break-all text-xl font-semibold"><?= $escape($request !== null ? $request['method'] . ' ' . $request['url'] : $trace['trace_id']) ?></h1>
                <p class="mono mt-1 text-xs text-[#9aa9a1]"><?= $escape($trace['trace_id']) ?></p>
            </header>

            <section class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-5" aria-label="Trace summary">
                <article class="panel p-4"><p class="text-[10px] uppercase text-[#9aa9a1]">Status</p><p class="mono mt-2 text-lg"><?= $escape($request['status_code'] ?? '—') ?></p></article>
                <article class="panel p-4"><p class="text-[10px] uppercase text-[#9aa9a1]">Duration</p><p class="mono mt-2 text-lg"><?= $escape($request !== null ? $ms($request['duration_ms']) : '—') ?></p></article>
                <article class="panel p-4"><p class="text-[10px] uppercase text-[#9aa9a1]">Queries</p><p class="mono mt-2 text-lg"><?= $escape(count($trace['queries'])) ?> <span class="text-xs text-[#9aa9a1]"><?= $escape($ms($totalQueryMs)) ?></span></p></article>
                <article class="panel p-4"><p class="text-[10px] uppercase text-[#9aa9a1]">Outbound</p><p class="mono mt-2 text-lg"><?= $escape(count($trace['outbound'])) ?></p></article>
                <article class="panel p-4"><p class="text-[10px] uppercase text-[#9aa9a1]">Route</p><p class="mt-2 break-all text-sm"><?= $escape($request['route'] ?? '—') ?></p><p class="text-xs text-[#9aa9a1]"><?= $escape($request['service'] ?? '') ?></p></article>
            </section>

            <?php if ($trace['exceptions'] !== []): ?>
                <section class="panel mt-5 p-4" aria-labelledby="trace-exceptions">
                    <h2 id="trace-exceptions" class="text-sm font-semibold text-[#f16d76]">Exceptions</h2>
                    <?php foreach ($trace['exceptions'] as $exception): ?>
                        <details class="mt-3 border-t border-[#2a3631] pt-3">
                            <summary class="cursor-pointer text-sm">
                                <span class="mono font-semibold"><?= $escape($exception['class'] ?: 'Error') ?></span>
                                <span class="text-[#f19a82]"><?= $escape($exception['message']) ?></span>
                                <span class="mono block text-xs text-[#9aa9a1]"><?= $escape($exception['file'] . ':' . $exception['line']) ?></span>
                            </summary>
                            <pre class="mono mt-2 overflow-x-auto whitespace-pre text-[11px] text-[#b1c0b7]"><?= $escape($exception['trace']) ?></pre>
                        </details>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <section class="panel mt-5 overflow-x-auto p-4" aria-labelledby="trace-queries">
                <h2 id="trace-queries" class="text-sm font-semibold">Queries</h2>
                <table class="mt-3 w-full min-w-[640px] text-left text-xs">
                    <thead><tr><th class="py-2 pr-3">#</th><th class="py-2 pr-3">Normalized SQL</th><th class="py-2 text-right">Duration</th></tr></thead>
                    <tbody class="divide-y divide-[#2a3631]">
                        <?php foreach ($trace['queries'] as $index => $query): ?>
                            <tr><td class="mono py-2 pr-3 text-[#9aa9a1]"><?= $escape($index + 1) ?></td><td class="py-2 pr-3"><code class="mono break-words"><?= $escape($query['normalized_sql']) ?></code></td><td class="mono py-2 text-right"><?= $escape($ms($query['duration_ms'])) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if ($trace['queries'] === []): ?><tr><td colspan="3" class="py-4 text-center text-[#9aa9a1]">No queries recorded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>

            <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
                <section class="panel overflow-x-auto p-4" aria-labelledby="trace-spans">
                    <h2 id="trace-spans" class="text-sm font-semibold">Spans</h2>
                    <table class="mt-3 w-full text-left text-xs">
                        <thead><tr><th class="py-2 pr-3">Name</th><th class="py-2 text-right">Duration</th></tr></thead>
                        <tbody class="divide-y divide-[#2a3631]">
                            <?php foreach ($trace['spans'] as $span): ?>
                                <tr><td class="py-2 pr-3 break-all"><?= $escape($span['name']) ?></td><td class="mono py-2 text-right"><?= $escape($ms($span['duration_ms'])) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($trace['spans'] === []): ?><tr><td colspan="2" class="py-4 text-center text-[#9aa9a1]">No spans recorded.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </section>
                <section class="panel overflow-x-auto p-4" aria-labelledby="trace-outbound">
                    <h2 id="trace-outbound" class="text-sm font-semibold">Outbound calls</h2>
                    <table class="mt-3 w-full text-left text-xs">
                        <thead><tr><th class="py-2 pr-3">Request</th><th class="py-2 pr-3">Status</th><th class="py-2 text-right">Duration</th></tr></thead>
                        <tbody class="divide-y divide-[#2a3631]">
                            <?php foreach ($trace['outbound'] as $call): ?>
                                <tr><td class="py-2 pr-3 break-all"><?= $escape($call['method'] . ' ' . $call['url']) ?></td><td class="mono py-2 pr-3"><?= $escape($call['status_code']) ?></td><td class="mono py-2 text-right"><?= $escape($ms($call['duration_ms'])) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($trace['outbound'] === []): ?><tr><td colspan="3" class="py-4 text-center text-[#9aa9a1]">No outbound calls recorded.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
