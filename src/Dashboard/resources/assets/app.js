/* PulsePHP dashboard behaviour. Loaded before Alpine.js (both deferred). */
(function () {
    'use strict';

    const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const numberFormat = new Intl.NumberFormat('en');

    function parseUtc(value) {
        if (!value) return null;
        const iso = value.includes('T') ? value : value.replace(' ', 'T') + 'Z';
        const date = new Date(iso);
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function relativeTime(value) {
        const date = parseUtc(value);
        if (!date) return '';
        const seconds = Math.round((Date.now() - date.getTime()) / 1000);
        if (seconds < 45) return 'just now';
        const units = [['day', 86400], ['hour', 3600], ['minute', 60]];
        const format = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
        for (const [unit, size] of units) {
            if (Math.abs(seconds) >= size) return format.format(-Math.round(seconds / size), unit);
        }
        return format.format(-seconds, 'second');
    }

    function localTime(value, withDate) {
        const date = parseUtc(value);
        if (!date) return value;
        const today = new Date().toDateString() === date.toDateString();
        return date.toLocaleString(undefined, withDate || !today
            ? { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }
            : { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }

    function formatMs(value) {
        const ms = Number(value) || 0;
        if (ms >= 1000) return (ms / 1000).toFixed(2) + ' s';
        return ms.toFixed(ms < 10 ? 1 : 0) + ' ms';
    }

    function localizeTimes(root) {
        root.querySelectorAll('time[data-local]').forEach((element) => {
            const value = element.getAttribute('datetime');
            element.title = localTime(value, true);
            element.textContent = element.dataset.local === 'relative' ? relativeTime(value) : localTime(value, false);
        });
    }

    function enableSorting(root) {
        root.querySelectorAll('table[data-sortable]').forEach((table) => {
            if (table.dataset.sortReady) return;
            table.dataset.sortReady = '1';
            table.querySelectorAll('th button[data-sort]').forEach((button) => {
                button.addEventListener('click', () => {
                    const header = button.closest('th');
                    const column = Array.from(header.parentElement.children).indexOf(header);
                    const ascending = button.getAttribute('aria-sort') !== 'ascending';
                    table.querySelectorAll('th button[data-sort]').forEach((other) => other.removeAttribute('aria-sort'));
                    button.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                    const numeric = button.dataset.sortType === 'number';
                    const body = table.tBodies[0];
                    const rows = Array.from(body.rows);
                    const valueOf = (row) => {
                        const cell = row.cells[column];
                        return cell ? (cell.dataset.sortValue ?? cell.textContent.trim()) : '';
                    };
                    rows.sort((a, b) => {
                        const left = valueOf(a);
                        const right = valueOf(b);
                        const result = numeric
                            ? (parseFloat(left.replace(/[^0-9.-]/g, '')) || 0) - (parseFloat(right.replace(/[^0-9.-]/g, '')) || 0)
                            : left.localeCompare(right, undefined, { numeric: true });
                        return ascending ? result : -result;
                    });
                    rows.forEach((row) => body.appendChild(row));
                });
            });
        });
    }

    /* ECG paper behind the main chart: minor and major grid in the plot area. */
    const ecgPaper = {
        id: 'ecgPaper',
        beforeDatasetsDraw(chart) {
            const { ctx, chartArea } = chart;
            if (!chartArea) return;
            const minor = 8;
            ctx.save();
            ctx.beginPath();
            ctx.rect(chartArea.left, chartArea.top, chartArea.right - chartArea.left, chartArea.bottom - chartArea.top);
            ctx.clip();
            for (let pass = 0; pass < 2; pass++) {
                ctx.beginPath();
                ctx.strokeStyle = cssVar(pass === 0 ? '--grid-minor' : '--grid-major');
                ctx.lineWidth = pass === 0 ? 0.5 : 1;
                const step = pass === 0 ? minor : minor * 5;
                for (let x = chartArea.left; x <= chartArea.right; x += step) {
                    ctx.moveTo(Math.round(x) + 0.5, chartArea.top);
                    ctx.lineTo(Math.round(x) + 0.5, chartArea.bottom);
                }
                for (let y = chartArea.bottom; y >= chartArea.top; y -= step) {
                    ctx.moveTo(chartArea.left, Math.round(y) + 0.5);
                    ctx.lineTo(chartArea.right, Math.round(y) + 0.5);
                }
                ctx.stroke();
            }
            ctx.restore();
        },
    };

    /* Vertical line for each deploy, labelled with its version. */
    const deployMarkers = {
        id: 'deployMarkers',
        afterDatasetsDraw(chart, _args, options) {
            const markers = options.markers || [];
            if (!markers.length) return;
            const { ctx, chartArea, scales } = chart;
            const labels = chart.data.labels || [];
            ctx.save();
            ctx.strokeStyle = cssVar('--php');
            ctx.fillStyle = cssVar('--php');
            ctx.font = '500 11px "Plex Sans", sans-serif';
            ctx.setLineDash([4, 3]);
            let lastLabelEnd = -Infinity;
            markers.slice().reverse().forEach((marker) => {
                let index = labels.indexOf(marker.bucket);
                if (index === -1) index = labels.findIndex((label) => label > marker.bucket);
                if (index === -1) return;
                const x = scales.x.getPixelForValue(index);
                ctx.beginPath();
                ctx.moveTo(x, chartArea.top + 14);
                ctx.lineTo(x, chartArea.bottom);
                ctx.stroke();
                // Deploys close together share one label slot; the line still marks each.
                const width = ctx.measureText(marker.version).width;
                const labelX = x + 4 + width > chartArea.right ? x - 4 - width : x + 4;
                if (labelX > lastLabelEnd) {
                    ctx.fillText(marker.version, labelX, chartArea.top + 10);
                    lastLabelEnd = labelX + width + 4;
                }
            });
            ctx.restore();
        },
    };

    function baseOptions(labels) {
        return {
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: cssVar('--surface'),
                    titleColor: cssVar('--ink'),
                    bodyColor: cssVar('--ink-2'),
                    borderColor: cssVar('--rule-strong'),
                    borderWidth: 1,
                    padding: 10,
                    titleFont: { family: 'Plex Sans', weight: '600' },
                    bodyFont: { family: 'Plex Sans' },
                    callbacks: { title: (items) => localTime(labels[items[0].dataIndex], true) },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { color: cssVar('--rule-strong') },
                    ticks: { color: cssVar('--ink-3'), maxTicksLimit: 8, maxRotation: 0, font: { family: 'Plex Sans', size: 11 },
                        callback: (_value, index) => localTime(labels[index], false).replace(/:\d\d$/, '') },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: cssVar('--rule') },
                    border: { display: false },
                    ticks: { color: cssVar('--ink-3'), precision: 0, font: { family: 'Plex Sans', size: 11 } },
                },
            },
        };
    }

    /* Small bar charts declared in markup: <canvas data-chart="bars" data-series="{labels, values}">. */
    function drawDeclaredCharts(root) {
        if (typeof Chart === 'undefined') return;
        root.querySelectorAll('canvas[data-chart="bars"]').forEach((canvas) => {
            Chart.getChart(canvas)?.destroy();
            const series = JSON.parse(canvas.dataset.series || '{"labels":[],"values":[]}');
            const color = cssVar(canvas.dataset.color || '--php');
            const options = baseOptions(series.labels);
            options.scales.y.grid.display = false;
            new Chart(canvas, {
                type: 'bar',
                data: { labels: series.labels, datasets: [{ data: series.values, backgroundColor: color, borderRadius: 2, maxBarThickness: 18 }] },
                options,
            });
        });
    }

    function refreshVisuals() {
        localizeTimes(document);
        drawDeclaredCharts(document);
        document.dispatchEvent(new CustomEvent('pulse:theme'));
    }

    window.pulseShell = (palette) => ({
        palette,
        paletteOpen: false,
        query: '',
        selected: 0,
        theme: (() => { try { return localStorage.getItem('pulse-theme') || 'system'; } catch (_) { return 'system'; } })(),
        init() {
            localizeTimes(document);
            enableSorting(document);
            drawDeclaredCharts(document);
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                if (this.theme === 'system') refreshVisuals();
            });
            setInterval(() => localizeTimes(document), 60000);
        },
        themeLabel() {
            return { system: 'Theme: system', light: 'Theme: light', dark: 'Theme: dark' }[this.theme];
        },
        cycleTheme() {
            this.theme = { system: 'light', light: 'dark', dark: 'system' }[this.theme];
            try {
                if (this.theme === 'system') localStorage.removeItem('pulse-theme');
                else localStorage.setItem('pulse-theme', this.theme);
            } catch (_) {}
            if (this.theme === 'system') delete document.documentElement.dataset.theme;
            else document.documentElement.dataset.theme = this.theme;
            refreshVisuals();
        },
        onKey(event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.paletteOpen ? this.closePalette() : this.openPalette();
            } else if (event.key === '/' && !this.paletteOpen && !/input|select|textarea/i.test(event.target.tagName)) {
                event.preventDefault();
                this.openPalette();
            }
        },
        openPalette() {
            this.paletteOpen = true;
            this.query = '';
            this.selected = 0;
            this.$nextTick(() => this.$refs.paletteInput.focus());
        },
        closePalette() { this.paletteOpen = false; },
        results() {
            const terms = this.query.toLowerCase().split(/\s+/).filter(Boolean);
            const matches = this.palette.filter((item) => {
                const haystack = (item.label + ' ' + item.kind).toLowerCase();
                return terms.every((term) => haystack.includes(term));
            });
            return matches.slice(0, terms.length ? 30 : 12);
        },
        move(step) {
            const count = this.results().length;
            if (count) this.selected = (this.selected + step + count) % count;
        },
        go() {
            const item = this.results()[this.selected];
            if (item) window.location.href = item.url;
        },
    });

    window.pulseOverview = (initial, endpoint) => ({
        live: initial,
        refreshSeconds: 0,
        refreshTimer: null,
        refreshing: false,
        refreshError: false,
        chart: null,
        init() {
            try { this.refreshSeconds = Number(localStorage.getItem('pulse-live')) === 5 ? 5 : 0; } catch (_) {}
            this.$nextTick(() => this.drawChart());
            document.addEventListener('pulse:theme', () => this.drawChart());
            this.schedule();
        },
        toggleLive() {
            this.refreshSeconds = this.refreshSeconds > 0 ? 0 : 5;
            try { localStorage.setItem('pulse-live', String(this.refreshSeconds)); } catch (_) {}
            this.schedule();
        },
        schedule() {
            if (this.refreshTimer) clearInterval(this.refreshTimer);
            this.refreshTimer = null;
            if (this.refreshSeconds > 0) {
                this.fetchSnapshot();
                this.refreshTimer = setInterval(() => this.fetchSnapshot(), this.refreshSeconds * 1000);
            }
        },
        async fetchSnapshot() {
            if (this.refreshing || document.hidden) return;
            this.refreshing = true;
            try {
                const url = new URL(endpoint, window.location.href);
                for (const [key, value] of new URLSearchParams(window.location.search)) url.searchParams.set(key, value);
                const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                this.live = await response.json();
                this.refreshError = false;
                this.drawChart();
            } catch (error) {
                this.refreshError = true;
                console.error('PulsePHP live refresh failed', error);
            } finally {
                this.refreshing = false;
            }
        },
        drawChart() {
            if (typeof Chart === 'undefined') return;
            const canvas = document.getElementById('overview-chart');
            if (!canvas) return;
            const data = this.live.chartData;
            const options = baseOptions(data.labels);
            options.plugins.deployMarkers = { markers: data.deploys };
            options.scales.y.grid.display = false;
            options.scales.y1 = { position: 'right', beginAtZero: true, grid: { display: false }, border: { display: false },
                ticks: { color: cssVar('--ink-3'), precision: 0, font: { family: 'Plex Sans', size: 11 } } };
            const datasets = [
                { label: 'Requests', data: data.requests, borderColor: cssVar('--trace'), backgroundColor: cssVar('--trace'),
                  borderWidth: 2, pointRadius: 0, pointHoverRadius: 4, tension: 0, fill: false, yAxisID: 'y', order: 0,
                  // The first and last buckets only cover part of their interval; draw them dashed
                  // so they don't read as a drop in traffic.
                  segment: { borderDash: (context) => context.p0DataIndex === 0 || context.p1DataIndex === data.labels.length - 1 ? [4, 4] : undefined } },
                { label: 'Exceptions', data: data.exceptions, type: 'bar', backgroundColor: cssVar('--php') + '8c',
                  borderRadius: 1, maxBarThickness: 8, yAxisID: 'y1', order: 1 },
            ];
            if (this.chart) {
                this.chart.destroy();
            }
            this.chart = new Chart(canvas, { type: 'line', data: { labels: data.labels, datasets }, options, plugins: [ecgPaper, deployMarkers] });
        },
        formatVital(vital) {
            if (vital.key === 'p95') return formatMs(vital.value).replace(/ (ms|s)$/, '');
            if (vital.key === 'error_rate') return (Number(vital.value) || 0).toFixed(1);
            return numberFormat.format(Math.round(Number(vital.value) || 0));
        },
        vitalUnit(vital) {
            if (vital.key === 'p95') return (Number(vital.value) || 0) >= 1000 ? 's' : 'ms';
            return vital.unit;
        },
        formatNumber: (value) => numberFormat.format(Number(value) || 0),
        formatFixed: (value, digits) => (Number(value) || 0).toFixed(digits),
        formatMs,
        relativeTime,
        shortClass: (name) => (name || '').split('\\').pop(),
        routeUrl(route) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', 'requests');
            url.searchParams.set('route', route);
            return url.search;
        },
        errorUrl(fingerprint) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', 'error');
            url.searchParams.set('id', fingerprint);
            return url.search;
        },
    });
})();
