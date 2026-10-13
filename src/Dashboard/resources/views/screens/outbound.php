<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */

$scope = $this->scope();
$maxCalls = max(array_column($data['hosts'], 'calls') ?: [1]);
?>
<div class="page-head">
    <div>
        <h1>Outbound HTTP</h1>
        <p>Calls your app made to other services. Credentials and query strings are removed before anything is stored.</p>
    </div>
</div>

<?php if ($data['hosts'] === []): ?>
    <section class="panel">
        <div class="empty">
            <strong>No outbound calls in the <?= $this->e(strtolower($scope['periodLabel'])) ?></strong>
            Laravel's <code>Http</code> client is captured automatically. For Guzzle, push <code>GuzzleMiddleware::create()</code> onto the handler stack.
        </div>
    </section>
<?php else: ?>
    <section class="panel" aria-labelledby="hosts-title">
        <div class="panel-head"><h2 id="hosts-title">By destination</h2><p>Failures are 5xx responses and connection errors.</p></div>
        <div class="table-wrap">
            <table data-sortable>
                <thead><tr>
                    <th>Host</th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Calls</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Failed</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Average</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Slowest</button></th>
                    <th>Last call</th>
                </tr></thead>
                <tbody>
                <?php foreach ($data['hosts'] as $host): ?>
                    <tr>
                        <td class="break"><strong><?= $this->e($host['host']) ?></strong>
                            <div class="bar" style="margin-top: 6px; max-width: 220px"><span style="width: <?= $this->e(round($host['calls'] / $maxCalls * 100, 1)) ?>%"></span></div></td>
                        <td class="num"><?= $this->e($this->count($host['calls'])) ?></td>
                        <td class="num"><?php if ($host['failures'] > 0): ?><span class="http" data-class="5"><?= $this->e($this->count($host['failures'])) ?></span><?php else: ?><span class="muted">0</span><?php endif; ?></td>
                        <td class="num" data-sort-value="<?= $this->e($host['avg_ms']) ?>"><?= $this->e($this->ms($host['avg_ms'])) ?></td>
                        <td class="num" data-sort-value="<?= $this->e($host['max_ms']) ?>"><?= $this->e($this->ms($host['max_ms'])) ?></td>
                        <td class="small secondary"><?= $this->time($host['last_call'], 'relative') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel" aria-labelledby="calls-title">
        <div class="panel-head"><h2 id="calls-title">Recent calls</h2></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Request</th><th>Status</th><th class="num">Duration</th><th>From route</th></tr></thead>
                <tbody>
                <?php foreach ($data['calls'] as $call): ?>
                    <tr>
                        <td class="small secondary"><?= $this->time($call['created_at']) ?></td>
                        <td class="break"><span class="method"><?= $this->e($call['method']) ?></span> <?= $this->e($call['url']) ?></td>
                        <td><?= $this->httpStatus($call['status_code']) ?></td>
                        <td class="num"><?= $this->e($this->ms($call['duration_ms'])) ?></td>
                        <td class="small secondary break">
                            <?php if ($call['trace_id'] !== ''): ?><a class="row-link" href="<?= $this->e($this->url(['trace' => $call['trace_id'], 'view' => null])) ?>"><?= $this->e($call['route']) ?></a><?php else: ?><?= $this->e($call['route']) ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
