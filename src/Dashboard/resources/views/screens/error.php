<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */
/** @var array<string, mixed> $page */

$detail = $data['detail'];
?>
<?php if ($detail === null): ?>
    <div class="page-head"><div><h1>Error not found</h1><p>It may have been pruned by the retention policy.</p></div></div>
    <a class="button" href="<?= $this->e($this->url(['view' => 'errors'])) ?>">Back to errors</a>
<?php else: ?>
    <?php $group = $detail['group']; ?>
    <div class="page-head">
        <div>
            <p class="small"><a class="secondary" href="<?= $this->e($this->url(['view' => 'errors'])) ?>">Errors</a></p>
            <h1 class="break"><?= $this->e($group['class'] ?: 'Error') ?></h1>
            <p class="break"><?= $this->e($group['message']) ?></p>
        </div>
        <div class="actions">
            <?php
            $actions = match ($group['status']) {
                'resolved', 'ignored' => ['reopen' => 'Reopen'],
                default => ['resolve' => 'Mark as resolved', 'ignore' => 'Ignore'],
            };
    foreach ($actions as $action => $label): ?>
                <form class="inline-form" method="post">
                    <input type="hidden" name="<?= $this->e($page['csrf']['name']) ?>" value="<?= $this->e($page['csrf']['value']) ?>">
                    <input type="hidden" name="action" value="<?= $action ?>">
                    <input type="hidden" name="fingerprint" value="<?= $this->e($group['fingerprint']) ?>">
                    <button class="button<?= $action === 'resolve' ? ' primary' : '' ?>" type="submit"><?= $label ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>

    <section class="panel">
        <dl class="facts">
            <div class="fact"><dt>Status</dt><dd><?= $this->errorStatus($group['status']) ?></dd></div>
            <div class="fact"><dt>Events</dt><dd><?= $this->e($this->count($group['occurrences'])) ?></dd></div>
            <div class="fact"><dt>Unhandled</dt><dd><?= $this->e($this->count($group['unhandled'])) ?></dd></div>
            <div class="fact"><dt>First seen</dt><dd class="small"><?= $this->time($group['first_seen'], 'relative') ?></dd></div>
            <div class="fact"><dt>Last seen</dt><dd class="small"><?= $this->time($group['last_seen'], 'relative') ?></dd></div>
        </dl>
        <div class="panel-chart" style="height: 160px; padding-top: 12px">
            <canvas data-chart="bars" data-color="--crit" data-series="<?= $this->e($this->json([
        'labels' => array_column($detail['timeline'], 'minute'),
        'values' => array_column($detail['timeline'], 'count'),
    ])) ?>" role="img" aria-label="Events over time"></canvas>
        </div>
    </section>

    <div class="grid-2">
        <section class="panel" aria-labelledby="occurrences-title">
            <div class="panel-head"><h2 id="occurrences-title">Recent events</h2><p>Open one to see what else happened in that request.</p></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>When</th><th>Route</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($detail['occurrences'] as $occurrence): ?>
                        <tr>
                            <td class="small secondary"><?= $this->time($occurrence['created_at']) ?></td>
                            <td class="break"><span class="method"><?= $this->e($occurrence['method']) ?></span> <?= $this->e($occurrence['route']) ?></td>
                            <td class="num"><?php if ($occurrence['trace_id'] !== ''): ?><a class="row-link small" href="<?= $this->e($this->url(['trace' => $occurrence['trace_id'], 'view' => null, 'id' => null])) ?>">Timeline</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="panel" aria-labelledby="routes-title">
            <div class="panel-head"><h2 id="routes-title">Where it happens</h2></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Route</th><th class="num">Events</th></tr></thead>
                    <tbody>
                    <?php $max = max(array_column($detail['routes'], 'count') ?: [1]); ?>
                    <?php foreach ($detail['routes'] as $route): ?>
                        <tr>
                            <td class="break"><span class="method"><?= $this->e($route['method']) ?></span> <?= $this->e($route['route']) ?>
                                <div class="bar" data-tone="crit" style="margin-top: 6px"><span style="width: <?= $this->e(round($route['count'] / $max * 100, 1)) ?>%"></span></div></td>
                            <td class="num"><?= $this->e($this->count($route['count'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="panel" style="margin-top: 20px" aria-labelledby="stack-title">
        <div class="panel-head"><h2 id="stack-title">Stack trace</h2><p class="break"><?= $this->e($group['file'] . ':' . $group['line']) ?></p></div>
        <pre class="stack code"><?= $this->e($detail['stack'] !== '' ? $detail['stack'] : 'No stack trace was recorded.') ?></pre>
    </section>
<?php endif; ?>
