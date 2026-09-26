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
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-xs text-[#b1c0b7]">
                    Auto-Refresh (Ao vivo)
                    <select aria-label="Auto-Refresh interval" x-model.number="refreshSeconds" @change="setRefreshInterval($event.target.value)" class="h-9 border border-[#2a3631] bg-[#121916] px-2 text-xs text-[#e8f0eb]">
                        <option value="0">Desativado</option>
                        <option value="1">1s</option>
                        <option value="2">2s</option>
                        <option value="5">5s</option>
                    </select>
                </label>
                <span x-show="refreshSeconds > 0" class="inline-flex items-center gap-2 text-[10px] font-semibold tracking-wider text-[#42c98a]" aria-live="polite">
                    <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#42c98a] opacity-70"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-[#42c98a]"></span></span>
                    LIVE
                </span>
                <span x-show="refreshError" class="text-[10px] text-[#f16d76]" role="status">Refresh failed</span>
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
                <p class="mono mt-3 text-[27px] font-medium leading-none"><span x-text="formatNumber(liveStats.requests_total)"><?= $escape($requestCount) ?></span></p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Average response</p>
                <p class="mono mt-3 text-[27px] font-medium leading-none"><span x-text="formatFixed(liveStats.avg_response_ms, 2)"><?= $escape($averageDuration) ?></span><span class="ml-1 text-sm text-[#718078]">ms</span></p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Peak request memory</p>
                <p class="mono mt-3 text-[27px] font-medium leading-none"><span x-text="formatFixed(liveStats.peak_memory_bytes / 1048576, 1)"><?= $escape($peakMemory) ?></span><span class="ml-1 text-sm text-[#718078]">MB</span></p>
            </article>
            <article class="panel p-4 sm:p-5">
                <p class="text-xs font-medium text-[#68776f]">Exceptions <span class="ml-1 text-[10px] uppercase text-[#9aa69f]"><?= $escape($periodLabel) ?></span></p>
                <p class="mono mt-3 text-[27px] font-medium leading-none"><span x-text="formatNumber(liveStats.exceptions_total)"><?= $escape($exceptionCount) ?></span></p>
            </article>
        </section>

        <section class="mt-5" aria-labelledby="routes-title">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="eyebrow text-[10px] font-semibold uppercase text-[#42c98a]">Route health</p>
                    <h2 id="routes-title" class="mt-1 text-lg font-semibold">Rotas &amp; APIs Monitoradas</h2>
                </div>
                <p class="text-xs text-[#9aa9a1]">Top <span x-text="routeCards.length"><?= count($routeCards) ?></span> routes in <?= $escape($periodLabel) ?></p>
            </div>
            <div x-show="routeCards.length === 0" x-cloak class="panel px-4 py-8 text-center text-sm text-[#9aa9a1]">Nenhuma rota foi registrada neste período/filtro.</div>
            <div x-show="routeCards.length > 0" x-cloak class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <template x-for="card in routeCards" :key="card.chart_id">
                    <article class="route-card panel p-4" :data-severity="card.health.key" :style="{ '--route-health': card.health.color }">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="break-all text-sm font-semibold" x-text="card.route"></p>
                                <p class="mt-1 truncate text-xs text-[#9aa9a1]" x-text="card.service"></p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="method-badge" :data-method="card.method" x-text="card.method"></span>
                                <span class="health-badge rounded px-2 py-1 text-[10px] font-semibold uppercase" x-text="card.health.label"></span>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <div><p class="text-[10px] uppercase text-[#89968f]">Requests</p><p class="mono mt-1 text-sm" x-text="formatNumber(card.total_requests)"></p></div>
                            <div><p class="text-[10px] uppercase text-[#89968f]">Avg</p><p class="mono mt-1 text-sm"><span x-text="formatFixed(card.avg_duration_ms, 1)"></span> ms</p></div>
                            <div><p class="text-[10px] uppercase text-[#89968f]">Errors</p><p class="mono mt-1 text-sm"><span x-text="formatFixed(card.error_rate_percent, 2)"></span>%</p></div>
                        </div>
                        <div class="route-sparkline mt-3"><canvas :id="'route-chart-' + card.chart_id" :aria-label="'Response time trend for ' + card.route" role="img"></canvas></div>
                        <p x-show="card.unhandled_exceptions > 0" x-cloak class="mt-2 text-xs text-[#f16d76]">Unhandled exceptions: <span x-text="card.unhandled_exceptions"></span></p>
                        <button type="button" class="mt-3 border border-[#2a3631] px-2 py-1.5 text-xs text-[#b1c0b7]" @click="toggleRouteDetails(card.chart_id)" :aria-expanded="routeExpanded(card.chart_id)">
                            <span x-text="routeExpanded(card.chart_id) ? 'Hide slow requests' : 'Show 5 slowest requests'"></span>
                        </button>
                        <div class="mt-3 border-t border-[#2a3631] pt-2" x-show="routeExpanded(card.chart_id)" x-cloak>
                            <template x-if="card.slow_requests.length === 0"><p class="py-2 text-xs text-[#9aa9a1]">No requests in this route.</p></template>
                            <ul class="space-y-2">
                                <template x-for="request in card.slow_requests" :key="request.created_at + request.url">
                                    <li class="flex items-start justify-between gap-3 text-xs">
                                        <span class="min-w-0 break-all text-[#b1c0b7]"><span x-text="request.url"></span><span class="ml-2 text-[#89968f]" x-text="request.status_code"></span></span>
                                        <span class="mono shrink-0" style="color: var(--route-health)"><span x-text="formatFixed(request.duration_ms, 1)"></span> ms</span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </article>
                </template>
            </div>
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
        const pulseInitialLiveData = <?= $liveDataJson ?>;
        const pulseChartData = <?= $chartsJson ?>;
        const pulseChartRegistry = { general: {}, routes: {} };
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
            liveStats: pulseInitialLiveData.stats,
            liveExceptions: pulseInitialLiveData.exceptions,
            routeCards: pulseInitialLiveData.routeCards,
            liveChartData: pulseInitialLiveData.chartData,
            refreshSeconds: 0,
            refreshTimer: null,
            refreshing: false,
            refreshError: false,
            expandedRoutes: {},
            lastUpdated: pulseInitialLiveData.generatedAt,
            init() {
                try {
                    const saved = JSON.parse(localStorage.getItem('pulse-dashboard-v2') || '{}');
                    const known = Object.keys(this.settings);
                    if (Array.isArray(saved.order)) {
                        this.order = [...new Set(saved.order.filter(id => known.includes(id)))];
                        this.order = this.order.concat(known.filter(id => !this.order.includes(id)));
                    }
                    const savedRefresh = Number(saved.refreshSeconds);
                    if ([0, 1, 2, 5].includes(savedRefresh)) this.refreshSeconds = savedRefresh;
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
                    this.startAutoRefresh();
                });
            },
            persist() {
                localStorage.setItem('pulse-dashboard-v2', JSON.stringify({
                    order: this.order,
                    settings: this.settings,
                    refreshSeconds: this.refreshSeconds
                }));
            },
            formatNumber(value) { return new Intl.NumberFormat().format(Number(value) || 0); },
            formatFixed(value, digits) { return (Number(value) || 0).toFixed(digits); },
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
                this.$nextTick(() => pulseChartRegistry.general[id]?.resize());
            },
            moveWidget(id, direction) {
                const current = this.order.indexOf(id);
                const next = current + direction;
                if (next < 0 || next >= this.order.length) return;
                [this.order[current], this.order[next]] = [this.order[next], this.order[current]];
                this.persist();
            },
            setRefreshInterval(value) {
                const interval = Number(value);
                this.refreshSeconds = [0, 1, 2, 5].includes(interval) ? interval : 0;
                this.persist();
                this.startAutoRefresh();
            },
            startAutoRefresh() {
                if (this.refreshTimer !== null) window.clearInterval(this.refreshTimer);
                this.refreshTimer = null;
                if (this.refreshSeconds === 0) return;
                this.fetchMetrics();
                this.refreshTimer = window.setInterval(
                    () => this.fetchMetrics(),
                    this.refreshSeconds * 1000
                );
            },
            async fetchMetrics() {
                if (this.refreshing) return;
                this.refreshing = true;
                try {
                    const endpoint = new URL('dashboard-data.php', window.location.href);
                    endpoint.search = window.location.search;
                    const response = await fetch(endpoint, {
                        cache: 'no-store',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' }
                    });
                    if (!response.ok) throw new Error(`Metrics request failed: ${response.status}`);
                    const snapshot = await response.json();

                    this.liveStats = snapshot.stats;
                    this.liveExceptions = snapshot.exceptions;
                    this.liveChartData = snapshot.chartData;
                    this.lastUpdated = snapshot.generatedAt;
                    this.refreshError = false;

                    for (const chart of Object.values(pulseChartRegistry.general)) chart.destroy();
                    pulseChartRegistry.general = {};
                    for (const chart of Object.values(pulseChartRegistry.routes)) chart.destroy();
                    pulseChartRegistry.routes = {};
                    this.routeCards = snapshot.routeCards;
                    this.$nextTick(() => {
                        this.initializeCharts();
                        this.initializeRouteCharts();
                        initializePulseTables();
                    });
                } catch (error) {
                    this.refreshError = true;
                    console.error('PulsePHP live refresh failed', error);
                } finally {
                    this.refreshing = false;
                }
            },
            initializeCharts() {
                if (typeof Chart === 'undefined') return;
                for (const chart of Object.values(pulseChartRegistry.general)) chart.destroy();
                pulseChartRegistry.general = {};
                const definitions = {
                    requests: { type: 'line', color: '#16805a' },
                    queries: { type: 'bar', color: '#d77a45' },
                    spans: { type: 'bar', color: '#437f96' },
                    outbound: { type: 'bar', color: '#7b8050' },
                    exceptions: { type: 'line', color: '#bb533d' }
                };
                for (const [id, definition] of Object.entries(definitions)) {
                    const canvas = document.getElementById(`${id}-chart`);
                    const data = this.liveChartData[id] || pulseChartData[id];
                    if (!canvas || !data) continue;
                    pulseChartRegistry.general[id] = new Chart(canvas, {
                        type: definition.type,
                        data: {
                            labels: [...data.labels],
                            datasets: [{
                                data: [...data.values],
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
                for (const [id, card] of this.routeCards.entries()) {
                    const canvas = document.getElementById(`route-chart-${card.chart_id}`);
                    const context = canvas?.getContext('2d');
                    if (!context) continue;
                    const gradient = context.createLinearGradient(0, 0, 0, 76);
                    gradient.addColorStop(0, `${card.health.color}55`);
                    gradient.addColorStop(1, `${card.health.color}00`);
                    pulseChartRegistry.routes[card.chart_id] = new Chart(context, {
                        type: 'line',
                        data: {
                            labels: card.timeline.map(point => point.timestamp),
                            datasets: [{
                                data: card.timeline.map(point => point.avg_duration_ms),
                                borderColor: card.health.color,
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
                if (table._pulseTableController) {
                    table._pulseTableController.sync();
                    return;
                }
                let rows = Array.from(body.rows).filter(row => !row.hasAttribute('data-empty-row'));
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

                const sortRows = () => {
                    if (sortColumn < 0) return;
                    const button = table.querySelector(`[data-sort-column="${sortColumn}"]`);
                    const numeric = button?.dataset.sortType === 'number';
                    rows.sort((left, right) => {
                        const leftValue = left.cells[sortColumn]?.dataset.sortValue ?? left.cells[sortColumn]?.textContent.trim() ?? '';
                        const rightValue = right.cells[sortColumn]?.dataset.sortValue ?? right.cells[sortColumn]?.textContent.trim() ?? '';
                        const comparison = numeric
                            ? (parseFloat(leftValue) || 0) - (parseFloat(rightValue) || 0)
                            : leftValue.localeCompare(rightValue);
                        return comparison * sortDirection;
                    });
                };

                table._pulseTableController = {
                    sync() {
                        rows = Array.from(body.rows).filter(row => !row.hasAttribute('data-empty-row'));
                        sortRows();
                        render();
                    }
                };

                table.querySelectorAll('[data-sort-column]').forEach(button => {
                    button.addEventListener('click', () => {
                        const column = Number(button.dataset.sortColumn);
                        sortDirection = sortColumn === column ? -sortDirection : 1;
                        sortColumn = column;
                        sortRows();
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