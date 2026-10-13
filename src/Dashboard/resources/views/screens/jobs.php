<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */

$scope = $this->scope();
?>
<div class="page-head">
    <div>
        <h1>Jobs</h1>
        <p>Queued jobs by class. A run counts as failed when it recorded an exception.</p>
    </div>
</div>

<section class="panel">
    <?php if ($data['jobs'] === []): ?>
        <div class="empty">
            <strong>No jobs in the <?= $this->e(strtolower($scope['periodLabel'])) ?></strong>
            Laravel queue jobs show up here automatically. In plain PHP, wrap each unit of work with <code>beginTrace()</code> and a timer named <code>job:YourJob</code>.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable>
                <thead><tr>
                    <th>Job</th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Runs</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Failed</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Average</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">p95</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Slowest</button></th>
                    <th>Last run</th>
                </tr></thead>
                <tbody>
                <?php foreach ($data['jobs'] as $job): ?>
                    <tr>
                        <td class="break" title="<?= $this->e($job['name']) ?>"><strong><?= $this->e($this->shortClass($job['name'])) ?></strong><p class="small muted"><?= $this->e($job['name']) ?></p></td>
                        <td class="num"><?= $this->e($this->count($job['runs'])) ?></td>
                        <td class="num"><?php if ($job['failures'] > 0): ?><span class="http" data-class="5"><?= $this->e($this->count($job['failures'])) ?></span><?php else: ?><span class="muted">0</span><?php endif; ?></td>
                        <td class="num" data-sort-value="<?= $this->e($job['avg_ms']) ?>"><?= $this->e($this->ms($job['avg_ms'])) ?></td>
                        <td class="num" data-sort-value="<?= $this->e($job['p95_ms']) ?>"><?= $this->e($this->ms($job['p95_ms'])) ?></td>
                        <td class="num" data-sort-value="<?= $this->e($job['max_ms']) ?>"><?= $this->e($this->ms($job['max_ms'])) ?></td>
                        <td class="small secondary"><?= $this->time($job['last_run'], 'relative') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
