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
    <meta name="color-scheme" content="light">
    <title>PulsePHP · Observability</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
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
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen" x-data="pulseDashboard()">
    <header class="border-b border-[#dce3de] bg-white">
        <div class="mx-auto flex max-w-[1440px] items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#123b32] text-sm font-bold text-[#aaf0c8]">P</span>
                <div>
                    <p class="text-sm font-bold leading-tight">Pulse<span class="font-normal text-[#617169]">PHP</span></p>
                    <p class="eyebrow mt-1 text-[10px] font-semibold uppercase text-[#718078]">Local observability</p>
                </div>
            </div>
            <details class="relative text-xs">
                <summary class="cursor-pointer rounded-md border border-[#dce3de] bg-white px-3 py-2 font-medium text-[#52645a]">Widgets</summary>
                <div class="absolute right-0 z-20 mt-2 w-56 border border-[#dce3de] bg-white p-3 shadow-lg">
                    <template x-for="widget in order" :key="widget">
                        <label class="flex cursor-pointer items-center gap-2 py-2 text-[#52645a]">
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
                <p class="eyebrow text-[11px] font-semibold uppercase text-[#16805a]">Runtime overview</p>
                <h1 class="mt-1 text-2xl font-semibold sm:text-[28px]">Application pulse</h1>
                <p class="mt-1 text-sm text-[#68776f]">Aggregated telemetry for the selected scope and period.</p>
            </div>
            <form method="get" class="flex flex-wrap items-end gap-2" aria-label="Global dashboard filters">
                <label class="grid gap-1 text-[11px] font-medium text-[#68776f]">
                    Period
                    <select name="period" class="h-9 min-w-36 border border-[#dce3de] bg-white px-2 text-xs text-[#18211d]">
                        <?php foreach ($periods as $key => $label): ?>
                            <option value="<?= $escape($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= $escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="grid gap-1 text-[11px] font-medium text-[#68776f]">
                    Service
                    <select name="service" class="h-9 min-w-40 border border-[#dce3de] bg-white px-2 text-xs text-[#18211d]">
                        <option value="">All services</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= $escape($service) ?>" <?= $selectedService === $service ? 'selected' : '' ?>><?= $escape($service) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="grid gap-1 text-[11px] font-medium text-[#68776f]">
                    Route / API
                    <select name="route" class="h-9 min-w-44 border border-[#dce3de] bg-white px-2 text-xs text-[#18211d]">
                        <option value="">All routes</option>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?= $escape($route) ?>" <?= $selectedRoute === $route ? 'selected' : '' ?>><?= $escape($route) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="h-9 bg-[#123b32] px-4 text-xs font-semibold text-white">Apply</button>
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
                });
            },
            persist() {
                localStorage.setItem('pulse-dashboard-v2', JSON.stringify({ order: this.order, settings: this.settings }));
            },
            isVisible(id) { return this.settings[id].visible; },
            isCollapsed(id) { return this.settings[id].collapsed; },
            currentView(id) { return this.settings[id].view; },
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