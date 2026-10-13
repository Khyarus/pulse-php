<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */

$trace = $data['trace'];
$waterfall = $data['waterfall'];
$kinds = ['request' => 'Request', 'span' => 'Span', 'query' => 'Query', 'http' => 'HTTP'];
?>
<?php if ($trace === null): ?>
    <div class="page-head"><div><h1>Trace not found</h1><p>It may have been pruned by the retention policy, or the request wasn't sampled.</p></div></div>
    <a class="button" href="<?= $this->e($this->url(['view' => 'requests'])) ?>">Back to requests</a>
<?php else: ?>
    <?php
    $request = $trace['request'];
    $queryMs = array_sum(array_map(static fn (array $query): float => (float) $query['duration_ms'], $trace['queries']));
    $outboundMs = array_sum(array_map(static fn (array $call): float => (float) $call['duration_ms'], $trace['outbound']));
    ?>
    <div class="page-head">
        <div>
            <p class="small"><a class="secondary" href="<?= $this->e($this->url(['view' => 'requests'])) ?>">Requests</a></p>
            <h1 class="break"><?= $this->e($request !== null ? $request['method'] . ' ' . $request['url'] : 'Background work') ?></h1>
            <p><?php if ($request !== null): ?><?= $this->httpStatus($request['status_code']) ?> in <?= $this->e($this->ms($request['duration_ms'])) ?>, route <?= $this->e($request['route']) ?>, <?= $this->time($request['created_at']) ?>.<?php else: ?>Trace <span class="code"><?= $this->e($trace['trace_id']) ?></span><?php endif; ?></p>
        </div>
    </div>

    <section class="panel">
        <dl class="facts" style="border-top: 0">
            <div class="fact"><dt>Database</dt><dd><?= $this->e($this->ms($queryMs)) ?> <span class="small muted"><?= $this->e($this->plural(count($trace['queries']), 'query', 'queries')) ?></span></dd></div>
            <div class="fact"><dt>Outbound HTTP</dt><dd><?= $this->e($this->ms($outboundMs)) ?> <span class="small muted"><?= $this->e($this->plural(count($trace['outbound']), 'call', 'calls')) ?></span></dd></div>
            <div class="fact"><dt>Exceptions</dt><dd><?= $this->e(count($trace['exceptions'])) ?></dd></div>
            <?php if ($request !== null): ?><div class="fact"><dt>Peak memory</dt><dd><?= $this->e($this->bytes((int) $request['memory_bytes'])) ?></dd></div><?php endif; ?>
        </dl>
    </section>

    <section class="panel" aria-labelledby="timeline-title">
        <div class="panel-head">
            <h2 id="timeline-title">Timeline</h2>
            <p>Each bar starts when the operation started.<?php if (array_filter($waterfall['rows'], static fn (array $row): bool => $row['estimated']) !== []): ?> Rows recorded by older versions are placed one after another.<?php endif; ?></p>
        </div>
        <div class="waterfall">
            <div class="wf-axis" aria-hidden="true">
                <?php foreach ($waterfall['ticks'] as $index => $tick): ?>
                    <span style="left: <?= $index * 25 ?>%"><?= $this->e($this->ms($tick)) ?></span>
                <?php endforeach; ?>
            </div>
            <?php foreach ($waterfall['rows'] as $row): ?>
                <div class="wf-row">
                    <div class="wf-label" title="<?= $this->e($row['label']) ?>">
                        <span class="wf-kind"><?= $this->e($kinds[$row['kind']]) ?></span>
                        <span class="<?= $row['kind'] === 'query' ? 'code' : '' ?>" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap"><?= $this->e($row['label']) ?></span>
                    </div>
                    <div class="wf-track">
                        <span class="wf-bar" data-kind="<?= $this->e($row['kind']) ?>" style="left: <?= $this->e($row['left']) ?>%; width: <?= $this->e($row['width']) ?>%"></span>
                        <?php $end = $row['left'] + $row['width']; ?>
                        <span class="wf-time" style="<?= match (true) {
                            $end <= 85 => 'left: calc(' . $this->e($end) . '% + 6px)',
                            $row['left'] > 15 => 'right: calc(' . $this->e(round(100 - $row['left'], 3)) . '% + 6px)',
                            default => 'right: 6px',
                        } ?>"><?= $this->e($this->ms($row['duration_ms'])) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <?php foreach ($trace['exceptions'] as $exception): ?>
        <section class="panel" aria-label="Exception">
            <div class="panel-head">
                <div>
                    <h2 class="break"><?= $this->e($exception['class'] ?: 'Error') ?></h2>
                    <p class="break"><?= $this->e($exception['message']) ?></p>
                </div>
                <a class="small secondary" href="<?= $this->e($this->url(['view' => 'error', 'id' => $exception['fingerprint']])) ?>">All events of this error</a>
            </div>
            <pre class="stack code"><?= $this->e($exception['trace']) ?></pre>
        </section>
    <?php endforeach; ?>

    <?php if ($trace['logs'] !== []): ?>
        <section class="panel" aria-labelledby="trace-logs-title">
            <div class="panel-head"><h2 id="trace-logs-title">Logs</h2></div>
            <div class="table-wrap">
                <table>
                    <tbody>
                    <?php foreach ($trace['logs'] as $log): ?>
                        <tr><td class="small secondary" style="width: 1%; white-space: nowrap"><?= $this->e(ucfirst($log['level'])) ?></td><td class="break"><?= $this->e($log['message']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
