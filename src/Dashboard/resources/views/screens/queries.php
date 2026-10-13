<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */

$scope = $this->scope();
$slowest = $data['slowQueries'];
$maxAvg = max(array_column($slowest, 'avg_duration_ms') ?: [1.0]);
?>
<div class="page-head">
    <div>
        <h1>Queries</h1>
        <p>SQL grouped by shape — literals are replaced by <code>?</code>, so the same query with different values counts once.</p>
    </div>
</div>

<section class="panel" aria-labelledby="nplus-title">
    <div class="panel-head">
        <h2 id="nplus-title">Possible N+1 queries</h2>
        <p>Same query run <?= $this->e($data['threshold']) ?> or more times in a single request or job.</p>
    </div>
    <?php if ($data['nPlusOne'] === []): ?>
        <div class="empty"><strong>No repeated queries found</strong>No request ran the same query <?= $this->e($data['threshold']) ?> or more times in the <?= $this->e(strtolower($scope['periodLabel'])) ?>.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable>
                <thead><tr>
                    <th>Query</th><th>Route</th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Most in one request</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Affected requests</button></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($data['nPlusOne'] as $pattern): ?>
                    <tr>
                        <td><code class="sql"><?= $this->e($pattern['normalized_sql']) ?></code></td>
                        <td class="small secondary break"><?= $this->e($pattern['route']) ?></td>
                        <td class="num"><?= $this->e($pattern['max_per_trace']) ?></td>
                        <td class="num"><?= $this->e($this->count($pattern['affected_traces'])) ?></td>
                        <td class="num"><a class="row-link small" href="<?= $this->e($this->url(['trace' => $pattern['sample_trace_id'], 'view' => null])) ?>">Example</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel" aria-labelledby="slow-title">
    <div class="panel-head">
        <h2 id="slow-title">Slowest queries</h2>
        <p>Sorted by average duration.</p>
    </div>
    <?php if ($slowest === []): ?>
        <div class="empty"><strong>No queries recorded</strong>In Laravel queries are captured automatically; in plain PHP use <code>TracedPDO</code>.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable>
                <thead><tr>
                    <th>Query</th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Average</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Slowest</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Runs</button></th>
                </tr></thead>
                <tbody>
                <?php foreach ($slowest as $query): ?>
                    <tr>
                        <td>
                            <code class="sql"><?= $this->e($query['normalized_sql']) ?></code>
                            <p class="small muted break"><?= $this->e($query['route']) ?></p>
                        </td>
                        <td class="num" data-sort-value="<?= $this->e($query['avg_duration_ms']) ?>">
                            <?= $this->e($this->ms($query['avg_duration_ms'])) ?>
                            <div class="bar" style="margin-top: 6px"><span style="width: <?= $this->e(round($query['avg_duration_ms'] / $maxAvg * 100, 1)) ?>%"></span></div>
                        </td>
                        <td class="num" data-sort-value="<?= $this->e($query['max_duration_ms']) ?>"><?= $this->e($this->ms($query['max_duration_ms'])) ?></td>
                        <td class="num"><?= $this->e($this->count($query['executions'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
