<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */

$storage = $data['storage'];
$labels = [
    'pulse_requests' => 'Requests',
    'pulse_queries' => 'Queries',
    'pulse_spans' => 'Spans',
    'pulse_exceptions' => 'Exceptions',
    'pulse_outbound_requests' => 'Outbound calls',
    'pulse_logs' => 'Log lines',
    'pulse_metrics' => 'Metrics and events',
    'pulse_deploys' => 'Deploys',
    'pulse_catalog' => 'Distinct query shapes',
];
$maxRows = max($storage['tables'] ?: [1]) ?: 1;
?>
<div class="page-head">
    <div>
        <h1>Storage &amp; settings</h1>
        <p>What PulsePHP keeps on disk and how to change it.</p>
    </div>
</div>

<section class="panel">
    <dl class="facts" style="border-top: 0">
        <div class="fact"><dt>Database size</dt><dd><?= $this->e($this->bytes($storage['size_bytes'])) ?></dd></div>
        <div class="fact"><dt>Oldest request</dt><dd class="small"><?= $this->time($storage['oldest'], 'relative') ?></dd></div>
        <div class="fact"><dt>N+1 threshold</dt><dd><?= $this->e($data['nPlusOneThreshold']) ?> runs</dd></div>
    </dl>
    <div class="panel-body" style="padding-top: 14px; border-top: 1px solid var(--rule)">
        <p class="small muted">File</p>
        <p class="code small break"><?= $this->e($storage['path']) ?></p>
    </div>
</section>

<div class="grid-2">
    <section class="panel" aria-labelledby="rows-title">
        <div class="panel-head"><h2 id="rows-title">Stored rows</h2></div>
        <div class="table-wrap">
            <table>
                <tbody>
                <?php foreach ($labels as $table => $label): ?>
                    <tr>
                        <td><?= $this->e($label) ?><div class="bar" style="margin-top: 6px"><span style="width: <?= $this->e(round(($storage['tables'][$table] ?? 0) / $maxRows * 100, 1)) ?>%"></span></div></td>
                        <td class="num"><?= $this->e($this->count($storage['tables'][$table] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel" aria-labelledby="maintenance-title">
        <div class="panel-head"><h2 id="maintenance-title">Keeping the file small</h2></div>
        <div class="panel-body">
            <p class="secondary">Old telemetry is deleted by the retention policy (7 days unless configured). Run it on a schedule:</p>
            <pre class="stack code" style="margin: 12px -20px; border-bottom: 1px solid var(--rule)">php artisan pulse:prune          # Laravel
vendor/bin/pulse prune --db=<?= $this->e(basename($storage['path'])) ?> --days=7</pre>
            <p class="secondary">Record fewer requests on busy apps with <code>PULSE_SAMPLE_RATE</code>, and skip noisy paths with <code>PULSE_IGNORE_PATHS</code>.</p>
        </div>
    </section>
</div>

<section class="panel" style="margin-top: 20px" aria-labelledby="deploys-title">
    <div class="panel-head">
        <h2 id="deploys-title">Deploys in the last 7 days</h2>
        <p>Marked with <code>php artisan pulse:deploy v1.2.3</code> or <code>vendor/bin/pulse deploy</code>.</p>
    </div>
    <?php if ($data['deploys'] === []): ?>
        <div class="empty"><strong>No deploys marked</strong>Mark one from your deploy script to see it on the overview chart.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Version</th><th>Service</th><th>When</th></tr></thead>
                <tbody>
                <?php foreach ($data['deploys'] as $deploy): ?>
                    <tr><td><strong><?= $this->e($deploy['version']) ?></strong></td><td class="secondary"><?= $this->e($deploy['service']) ?></td><td class="small secondary"><?= $this->time($deploy['created_at']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
