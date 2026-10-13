<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */
/** @var array<string, mixed> $page */

$scope = $this->scope();
$live = $data['live'];
$subject = $scope['route'] ?? $scope['service'] ?? 'your application';
$lastDeploy = $data['deploys'][0] ?? null;
?>
<div x-data="pulseDashboard()">
    <div class="page-head">
        <div>
            <h1>Overview</h1>
            <p>How <?= $this->e($subject) ?> behaved over the <?= $this->e(strtolower($scope['periodLabel'])) ?>.
                <?php if ($lastDeploy !== null): ?>Last deploy: <strong><?= $this->e($lastDeploy['version']) ?></strong>, <?= $this->time($lastDeploy['created_at'], 'relative') ?>.<?php endif; ?></p>
        </div>
        <div class="actions">
            <button type="button" class="button live" :data-on="refreshSeconds > 0" @click="toggleLive()" :aria-pressed="refreshSeconds > 0">
                <span class="live-dot" aria-hidden="true"></span>
                <span x-text="refreshSeconds > 0 ? 'Live, every 5 s' : 'Live updates off'">Live updates off</span>
            </button>
            <span class="small muted" x-show="refreshError" x-cloak role="status">Couldn't refresh. Retrying.</span>
        </div>
    </div>

    <section class="vitals" aria-label="Vital signs">
        <template x-for="vital in live.vitals" :key="vital.key">
            <div class="vital">
                <p class="vital-label" x-text="vital.label"></p>
                <p class="vital-value"><span x-text="formatVital(vital)"></span><small x-text="vitalUnit(vital)"></small></p>
                <p class="vital-delta" :data-trend="vital.trend" x-text="vital.delta"></p>
            </div>
        </template>
    </section>
    <section class="ecg" aria-label="Requests and exceptions over time">
        <div class="ecg-legend" aria-hidden="true">
            <span><i class="swatch" style="color: var(--trace)"></i>Requests</span>
            <span><i class="swatch block" style="color: var(--php)"></i>Exceptions</span>
        </div>
        <canvas id="overview-chart" role="img" aria-label="Requests and exceptions per time bucket"></canvas>
    </section>

    <div class="grid-2">
        <section class="panel" aria-labelledby="routes-title">
            <div class="panel-head">
                <h2 id="routes-title">Routes that need attention</h2>
                <a class="small secondary" href="<?= $this->e($this->url(['view' => 'requests'])) ?>">All requests</a>
            </div>
            <div class="table-wrap" x-show="live.routeCards.length > 0">
                <table data-sortable>
                    <thead><tr><th>Route</th><th class="num">Requests</th><th class="num">p95</th><th class="num">Errors</th><th>Health</th></tr></thead>
                    <tbody>
                    <template x-for="card in live.routeCards.slice(0, 8)" :key="card.chart_id">
                        <tr :data-severity="card.health.key">
                            <td class="fill">
                                <div class="truncate"><span class="method" :data-method="card.method" x-text="card.method"></span>
                                <a class="row-link" :href="routeUrl(card.route)" :title="card.route" x-text="card.route"></a></div>
                            </td>
                            <td class="num" x-text="formatNumber(card.total_requests)"></td>
                            <td class="num" x-text="formatMs(card.p95_duration_ms)"></td>
                            <td class="num" x-text="formatFixed(card.error_rate_percent, 1) + '%'"></td>
                            <td><span class="status" :data-tone="card.health.color" x-text="card.health.label"></span></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>
            <div class="empty" x-show="live.routeCards.length === 0" x-cloak>
                <strong>No requests in this period</strong>
                Send some traffic to your app, or pick a longer period.
            </div>
        </section>

        <section class="panel" aria-labelledby="errors-title">
            <div class="panel-head">
                <h2 id="errors-title">Open errors</h2>
                <a class="small secondary" href="<?= $this->e($this->url(['view' => 'errors'])) ?>">All errors</a>
            </div>
            <div class="table-wrap" x-show="live.exceptionGroups.length > 0">
                <table>
                    <thead><tr><th>Error</th><th class="num">Events</th><th>Last seen</th></tr></thead>
                    <tbody>
                    <template x-for="group in live.exceptionGroups" :key="group.fingerprint">
                        <tr>
                            <td class="fill">
                                <a class="row-link" :href="errorUrl(group.fingerprint)" :title="group.class">
                                    <strong x-text="shortClass(group.class) || 'Error'"></strong>
                                    <span class="badge" data-tone="crit" x-show="group.status === 'regressed'">Regressed</span>
                                </a>
                                <p class="small secondary truncate" x-text="group.message"></p>
                            </td>
                            <td class="num" x-text="formatNumber(group.occurrences)"></td>
                            <td class="small secondary" style="white-space: nowrap" x-text="relativeTime(group.last_seen)"></td>
                        </tr>
                    </template>
                    </tbody>
                </table>
            </div>
            <div class="empty" x-show="live.exceptionGroups.length === 0" x-cloak>
                <strong>No open errors</strong>
                Nothing new or regressed in this period.
            </div>
        </section>
    </div>

    <?php if ($data['nPlusOne'] !== []): ?>
        <section class="panel" style="margin-top: 20px" aria-labelledby="nplus-title">
            <div class="panel-head">
                <h2 id="nplus-title">Possible N+1 queries</h2>
                <a class="small secondary" href="<?= $this->e($this->url(['view' => 'queries'])) ?>">All queries</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Query</th><th>Route</th><th class="num">Runs in one request</th></tr></thead>
                    <tbody>
                    <?php foreach ($data['nPlusOne'] as $pattern): ?>
                        <tr>
                            <td><code class="sql"><?= $this->e($pattern['normalized_sql']) ?></code></td>
                            <td class="secondary"><?= $this->e($pattern['route']) ?></td>
                            <td class="num"><a class="row-link" href="<?= $this->e($this->url(['trace' => $pattern['sample_trace_id'], 'view' => null])) ?>"><?= $this->e($pattern['max_per_trace']) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
<script>
    const pulseInitialLiveData = <?= $this->json($live) ?>;
    const pulseDataEndpoint = <?= $this->json($page['dataEndpoint']) ?>;
    window.pulseDashboard = () => window.pulseOverview(pulseInitialLiveData, pulseDataEndpoint);
</script>
