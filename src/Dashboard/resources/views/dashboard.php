<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>PulsePHP · Observability</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
            --pulse-bg: #0b100e;
            --pulse-panel: #121916;
            --pulse-raised: #19211e;
            --pulse-border: #2a3631;
            --pulse-text: #e8f0eb;
            --pulse-muted: #9aa9a1;
            --pulse-green: #42c98a;
            --pulse-warning: #e7b957;
            --pulse-critical: #f16d76;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--pulse-bg); color: var(--pulse-text); }
        .mono { font-family: 'DM Mono', monospace; }
        .panel { border: 1px solid var(--pulse-border); border-radius: 8px; background: var(--pulse-panel); }
        .eyebrow { letter-spacing: .08em; }
        .scroll-table { scrollbar-width: thin; scrollbar-color: #42534b transparent; }
        header { background: #0e1411 !important; border-color: var(--pulse-border) !important; }
        [class~="bg-white"] { background-color: var(--pulse-panel) !important; }
        [class~="bg-[#f8faf8]"] { background-color: var(--pulse-raised) !important; }
        [class~="border-[#dce3de]"], [class~="border-[#e8ede9]"] { border-color: var(--pulse-border) !important; }
        [class~="text-[#18211d]"] { color: var(--pulse-text) !important; }
        [class~="text-[#68776f]"], [class~="text-[#718078]"], [class~="text-[#52645a]"],
        [class~="text-[#89968f]"], [class~="text-[#77857d]"], [class~="text-[#9aa69f]"] {
            color: var(--pulse-muted) !important;
        }
        [class~="text-[#16805a]"] { color: var(--pulse-green) !important; }
        [class~="text-[#9d482e]"] { color: #f19a82 !important; }
        [class~="divide-[#edf0ee]"] > :not([hidden]) ~ :not([hidden]) { border-color: var(--pulse-border) !important; }
        button:disabled { cursor: not-allowed; opacity: .4; }
        button:not(:disabled):hover { filter: brightness(1.12); }
        select option { background: var(--pulse-panel); color: var(--pulse-text); }
        :focus-visible { outline: 2px solid #65dca0; outline-offset: 2px; }
        .route-card { border-left: 3px solid var(--route-health); }
        .health-badge { color: var(--route-health); background: color-mix(in srgb, var(--route-health) 14%, transparent); }
        .method-badge { border: 1px solid currentColor; border-radius: 4px; padding: 3px 6px; font: 500 10px 'DM Mono', monospace; }
        .method-badge[data-method="GET"] { color: #78adff; }
        .method-badge[data-method="POST"] { color: #49cf95; }
        .method-badge[data-method="PUT"] { color: #e7b957; }
        .method-badge[data-method="DELETE"] { color: #f16d76; }
        .method-badge[data-method="PATCH"] { color: #69c8c0; }
        .route-sparkline { height: 76px; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen" x-data="pulseDashboard()">
    <header class="border-b border-[#2a3631] bg-[#0e1411]">
        <div class="mx-auto flex max-w-[1440px] items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#143b2c] text-sm font-bold text-[#9af0c4]">P</span>
                <div>
                    <p class="text-sm font-bold leading-tight">Pulse<span class="font-normal text-[#617169]">PHP</span></p>
                    <p class="eyebrow mt-1 text-[10px] font-semibold uppercase text-[#718078]">Local observability</p>
                </div>
            </div>
            <details class="relative text-xs">
                <summary class="cursor-pointer rounded-md border border-[#2a3631] bg-[#121916] px-3 py-2 font-medium text-[#b1c0b7]">Widgets</summary>
                <div class="absolute right-0 z-20 mt-2 w-56 border border-[#2a3631] bg-[#121916] p-3 shadow-lg">
                    <template x-for="widget in order" :key="widget">
                        <label class="flex cursor-pointer items-center gap-2 py-2 text-[#b1c0b7]">
                            <input type="checkbox" :checked="settings[widget].visible" @change="setVisible(widget, $event.target.checked)">
                            <span x-text="labels[widget]"></span>
                        </label>
                    </template>
                </div>
            </details>
        </div>
    </header>

    <main class="mx-auto max-w-[1440px] px-4 py-7 sm:px-6 lg:px-8 lg:py-9">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="eyebrow text-[11px] font-semibold uppercase text-[#42c98a]">Runtime overview</p>
                <h1 class="mt-1 text-2xl font-semibold sm:text-[28px]">Application pulse</h1>
                <p class="mt-1 text-sm text-[#68776f]">Aggregated telemetry for the selected scope and period.</p>
            </div>
            <form method="get" class="flex flex-wrap items-end gap-2" aria-label="Global dashboard filters">
                <label class="grid gap-1 text-[11px] font-medium text-[#68776f]">
                    Period
                    <select name="period" class="h-9 min-w-36 border border-[#2a3631] bg-[#121916] px-2 text-xs text-[#e8f0eb]">
                        <?php foreach ($periods as $key => $label): ?>
                            <option value="<?= $escape($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= $escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="grid gap-1 text-[11px] font-medium text-[#68776f]">
                    Service
                    <select name="service" class="h-9 min-w-40 border border-[#2a3631] bg-[#121916] px-2 text-xs text-[#e8f0eb]">
                        <option value="">All services</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= $escape($service) ?>" <?= $selectedService === $service ? 'selected' : '' ?>><?= $escape($service) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="grid gap-1 text-[11px] font-medium text-[#68776f]">
                    Route / API
                    <select name="route" class="h-9 min-w-44 border border-[#2a3631] bg-[#121916] px-2 text-xs text-[#e8f0eb]">
                        <option value="">All routes</option>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?= $escape($route) ?>" <?= $selectedRoute === $route ? 'selected' : '' ?>><?= $escape($route) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="h-9 bg-[#17603f] px-4 text-xs font-semibold text-white">Apply</button>
            </form>
        </div>

        <section aria-label="Summary statistics" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Requests <span class="ml-1 text-[10px] uppercase text-[#9aa69f]"><?= $escape($periodLabel) ?></span></p>
                <p class="mono mt-3 text-[27px] font-medium leading-none"><?= $escape($requestCount) ?></p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Average response</p>
                <p class="mono mt-3 text-[27px] font-medium leading-none"><?= $escape($averageDuration) ?><span class="ml-1 text-sm text-[#718078]">ms</span></p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Peak request memory</p>
                <p class="mono mt-3 text-[27px] font-medium leading-none"><?= $escape($peakMemory) ?><span class="ml-1 text-sm text-[#718078]">MB</span></p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Exceptions <span class="ml-1 text-[10px] uppercase text-[#9aa69f]"><?= $escape($periodLabel) ?></span></p>
                <p class="mono mt-3 text-[27px] font-medium leading-none"><?= $escape($exceptionCount) ?></p>
            </article>
        </section>

        <section class="mt-5" aria-labelledby="routes-title">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="eyebrow text-[10px] font-semibold uppercase text-[#42c98a]">Route health</p>
                    <h2 id="routes-title" class="mt-1 text-lg font-semibold">Rotas &amp; APIs Monitoradas</h2>
                </div>
                <p class="text-xs text-[#9aa9a1]">Top <?= count($routeCards) ?> routes in <?= $escape($periodLabel) ?></p>
            </div>
            <?php if ($routeCards === []): ?>
                <div class="panel px-4 py-8 text-center text-sm text-[#9aa9a1]">Nenhuma rota foi registrada neste período/filtro.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($routeCards as $card): ?>
                        <article class="route-card panel p-4" data-severity="<?= $escape($card['health']['key']) ?>" style="--route-health: <?= $escape($card['health']['color']) ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="break-all text-sm font-semibold"><?= $escape($card['route']) ?></p>
                                    <p class="mt-1 truncate text-xs text-[#9aa9a1]"><?= $escape($card['service']) ?></p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="method-badge" data-method="<?= $escape($card['method']) ?>"><?= $escape($card['method']) ?></span>
                                    <span class="health-badge rounded px-2 py-1 text-[10px] font-semibold uppercase"><?= $escape($card['health']['label']) ?></span>
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-3 gap-2">
                                <div><p class="text-[10px] uppercase text-[#89968f]">Requests</p><p class="mono mt-1 text-sm"><?= $escape(number_format($card['total_requests'])) ?></p></div>
                                <div><p class="text-[10px] uppercase text-[#89968f]">Avg</p><p class="mono mt-1 text-sm"><?= $escape(number_format($card['avg_duration_ms'], 1)) ?> ms</p></div>
                                <div><p class="text-[10px] uppercase text-[#89968f]">Errors</p><p class="mono mt-1 text-sm"><?= $escape(number_format($card['error_rate_percent'], 2)) ?>%</p></div>
                            </div>
                            <div class="route-sparkline mt-3"><canvas id="route-chart-<?= $escape($card['chart_id']) ?>" aria-label="Response time trend for <?= $escape($card['route']) ?>" role="img"></canvas></div>
                            <?php if ($card['unhandled_exceptions'] > 0): ?>
                                <p class="mt-2 text-xs text-[#f16d76]">Unhandled exceptions: <?= $escape($card['unhandled_exceptions']) ?></p>
                            <?php endif; ?>
                            <button type="button" class="mt-3 border border-[#2a3631] px-2 py-1.5 text-xs text-[#b1c0b7]" @click="toggleRouteDetails('<?= $escape($card['chart_id']) ?>')" :aria-expanded="routeExpanded('<?= $escape($card['chart_id']) ?>')">
                                <span x-text="routeExpanded('<?= $escape($card['chart_id']) ?>') ? 'Hide slow requests' : 'Show 5 slowest requests'"></span>
                            </button>
                            <div class="mt-3 border-t border-[#2a3631] pt-2" x-show="routeExpanded('<?= $escape($card['chart_id']) ?>')" x-cloak>
                                <?php if ($card['slow_requests'] === []): ?>
                                    <p class="py-2 text-xs text-[#9aa9a1]">No requests in this route.</p>
                                <?php else: ?>
                                    <ul class="space-y-2">
                                        <?php foreach ($card['slow_requests'] as $slowRequest): ?>
                                            <li class="flex items-start justify-between gap-3 text-xs">
                                                <span class="min-w-0 break-all text-[#b1c0b7]"><?= $escape($slowRequest['url']) ?><span class="ml-2 text-[#89968f]"><?= $escape($slowRequest['status_code']) ?></span></span>
                                                <span class="mono shrink-0" style="color: var(--route-health)"><?= $escape(number_format($slowRequest['duration_ms'], 1)) ?> ms</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
            <?php $widgetId = 'requests'; $widgetLabel = 'Requests'; require __DIR__ . '/widgets/requests.php'; ?>
            <?php $widgetId = 'queries'; $widgetLabel = 'Slow queries'; require __DIR__ . '/widgets/queries.php'; ?>
            <?php $widgetId = 'spans'; $widgetLabel = 'Spans'; require __DIR__ . '/widgets/spans.php'; ?>
            <?php $widgetId = 'outbound'; $widgetLabel = 'Outbound APIs'; require __DIR__ . '/widgets/outbound.php'; ?>
            <?php $widgetId = 'exceptions'; $widgetLabel = 'Exceptions'; require __DIR__ . '/widgets/exceptions.php'; ?>
        </div>

        <footer class="pb-4 pt-5 text-center text-[11px] text-[#89968f]">PulsePHP · local telemetry</footer>
    </main>

    <script>
        const pulseChartData = <?= $chartsJson ?>;
        const pulseRouteChartData = <?= $routeChartsJson ?>;
        window.pulseDashboard = () => ({
            labels: {
                requests: 'Requests',
                queries: 'Slow queries',
                spans: 'Spans',
                outbound: 'Outbound APIs',
                exceptions: 'Exceptions'
            },
            order: ['requests', 'queries', 'spans', 'outbound', 'exceptions'],
            settings: {
                requests: { visible: true, collapsed: false, view: 'chart' },
                queries: { visible: true, collapsed: false, view: 'table' },
                spans: { visible: true, collapsed: false, view: 'table' },
                outbound: { visible: true, collapsed: false, view: 'table' },
                exceptions: { visible: true, collapsed: false, view: 'table' }
            },
            charts: {},
            expandedRoutes: {},
            init() {
                try {
                    const saved = JSON.parse(localStorage.getItem('pulse-dashboard-v2') || '{}');
                    const known = Object.keys(this.settings);
                    if (Array.isArray(saved.order)) {
                        this.order = [...new Set(saved.order.filter(id => known.includes(id)))];
                        this.order = this.order.concat(known.filter(id => !this.order.includes(id)));
                    }
                    if (saved.settings && typeof saved.settings === 'object') {
                        for (const id of known) {
                            const preference = saved.settings[id];
                            if (!preference || typeof preference !== 'object') continue;
                            if (typeof preference.visible === 'boolean') this.settings[id].visible = preference.visible;
                            if (typeof preference.collapsed === 'boolean') this.settings[id].collapsed = preference.collapsed;
                            if (['chart', 'table'].includes(preference.view)) this.settings[id].view = preference.view;
                        }
                    }
                } catch (_) {}
                this.$nextTick(() => {
                    initializePulseTables();
                    this.initializeCharts();
                    this.initializeRouteCharts();
                });
            },
            persist() {
                localStorage.setItem('pulse-dashboard-v2', JSON.stringify({ order: this.order, settings: this.settings }));
            },
            isVisible(id) { return this.settings[id].visible; },
            isCollapsed(id) { return this.settings[id].collapsed; },
            currentView(id) { return this.settings[id].view; },
            routeExpanded(id) { return this.expandedRoutes[id] === true; },
            toggleRouteDetails(id) { this.expandedRoutes[id] = !this.routeExpanded(id); },
            widgetOrder(id) { return this.order.indexOf(id); },
            setVisible(id, visible) { this.settings[id].visible = visible; this.persist(); },
            toggleCollapsed(id) { this.settings[id].collapsed = !this.settings[id].collapsed; this.persist(); },
            setView(id, view) {
                this.settings[id].view = view;
                this.persist();
                this.$nextTick(() => this.charts[id]?.resize());
            },
            moveWidget(id, direction) {
                const current = this.order.indexOf(id);
                const next = current + direction;
                if (next < 0 || next >= this.order.length) return;
                [this.order[current], this.order[next]] = [this.order[next], this.order[current]];
                this.persist();
            },
            initializeCharts() {
                if (typeof Chart === 'undefined') return;
                const definitions = {
                    requests: { type: 'line', color: '#16805a' },
                    queries: { type: 'bar', color: '#d77a45' },
                    spans: { type: 'bar', color: '#437f96' },
                    outbound: { type: 'bar', color: '#7b8050' },
                    exceptions: { type: 'line', color: '#bb533d' }
                };
                for (const [id, definition] of Object.entries(definitions)) {
                    const canvas = document.getElementById(`${id}-chart`);
                    const data = pulseChartData[id];
                    if (!canvas || !data) continue;
                    this.charts[id] = new Chart(canvas, {
                        type: definition.type,
                        data: {
                            labels: data.labels,
                            datasets: [{
                                data: data.values,
                                borderColor: definition.color,
                                backgroundColor: definition.type === 'line' ? `${definition.color}22` : `${definition.color}bb`,
                                borderWidth: 2,
                                pointRadius: definition.type === 'line' ? 2 : 0,
                                fill: definition.type === 'line',
                                tension: 0.3
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            indexAxis: id === 'queries' || id === 'spans' || id === 'outbound' ? 'y' : 'x',
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
                                y: { beginAtZero: true, ticks: { precision: 0 } }
                            }
                        }
                    });
                }
            },
            initializeRouteCharts() {
                if (typeof Chart === 'undefined') return;
                for (const [id, data] of Object.entries(pulseRouteChartData)) {
                    const canvas = document.getElementById(`route-chart-${id}`);
                    const context = canvas?.getContext('2d');
                    if (!context) continue;
                    const gradient = context.createLinearGradient(0, 0, 0, 76);
                    gradient.addColorStop(0, `${data.color}55`);
                    gradient.addColorStop(1, `${data.color}00`);
                    new Chart(context, {
                        type: 'line',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                data: data.values,
                                borderColor: data.color,
                                backgroundColor: gradient,
                                borderWidth: 2,
                                pointRadius: 0,
                                fill: true,
                                tension: 0.35
                            }]
                        },
                        options: {
                            maintainAspectRatio: false,
                            animation: false,
                            plugins: { legend: { display: false }, tooltip: { enabled: true } },
                            scales: { x: { display: false }, y: { display: false, beginAtZero: true } }
                        }
                    });
                }
            }
        });

        function initializePulseTables() {
            document.querySelectorAll('table[data-sortable]').forEach(table => {
                const body = table.tBodies[0];
                if (!body) return;
                let rows = Array.from(body.rows);
                const pageSize = 10;
                let page = 0;
                let sortColumn = -1;
                let sortDirection = 1;
                const wrapper = table.closest('[data-table-widget]');
                const pager = wrapper?.querySelector('[data-table-pager]');
                if (!pager) return;

                const render = () => {
                    const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));
                    page = Math.min(page, pageCount - 1);
                    rows.forEach((row, index) => {
                        row.hidden = index < page * pageSize || index >= (page + 1) * pageSize;
                        body.appendChild(row);
                    });
                    pager.querySelector('[data-page-label]').textContent = `Page ${page + 1} of ${pageCount} · ${rows.length} rows`;
                    pager.querySelector('[data-prev]').disabled = page === 0;
                    pager.querySelector('[data-next]').disabled = page >= pageCount - 1;
                };

                table.querySelectorAll('[data-sort-column]').forEach(button => {
                    button.addEventListener('click', () => {
                        const column = Number(button.dataset.sortColumn);
                        sortDirection = sortColumn === column ? -sortDirection : 1;
                        sortColumn = column;
                        const numeric = button.dataset.sortType === 'number';
                        rows.sort((left, right) => {
                            const leftValue = left.cells[column]?.dataset.sortValue ?? left.cells[column]?.textContent.trim() ?? '';
                            const rightValue = right.cells[column]?.dataset.sortValue ?? right.cells[column]?.textContent.trim() ?? '';
                            const comparison = numeric
                                ? (parseFloat(leftValue) || 0) - (parseFloat(rightValue) || 0)
                                : leftValue.localeCompare(rightValue);
                            return comparison * sortDirection;
                        });
                        page = 0;
                        render();
                    });
                });
                pager.querySelector('[data-prev]').addEventListener('click', () => { page--; render(); });
                pager.querySelector('[data-next]').addEventListener('click', () => { page++; render(); });
                render();
            });
        }
    </script>
</body>
</html>