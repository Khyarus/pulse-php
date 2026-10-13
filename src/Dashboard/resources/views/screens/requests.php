<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */

$scope = $this->scope();
$filters = $data['filters'];
$pages = max(1, (int) ceil($data['total'] / $data['perPage']));
$first = $data['total'] === 0 ? 0 : ($data['page'] - 1) * $data['perPage'] + 1;
$last = min($data['total'], $data['page'] * $data['perPage']);
?>
<div class="page-head">
    <div>
        <h1>Requests</h1>
        <p>Every recorded request in the <?= $this->e(strtolower($scope['periodLabel'])) ?>, newest first. Open one to see its timeline.</p>
    </div>
</div>

<section class="panel">
    <form class="filters" method="get" style="padding-top: 16px" aria-label="Filter requests">
        <input type="hidden" name="view" value="requests">
        <input type="hidden" name="period" value="<?= $this->e($scope['period']) ?>">
        <?php if ($scope['service'] !== null): ?><input type="hidden" name="service" value="<?= $this->e($scope['service']) ?>"><?php endif; ?>
        <?php if ($scope['route'] !== null): ?><input type="hidden" name="route" value="<?= $this->e($scope['route']) ?>"><?php endif; ?>
        <label>URL or route contains
            <input class="field" type="search" name="q" value="<?= $this->e($filters['search'] ?? '') ?>" placeholder="/orders" size="24">
        </label>
        <label>Status
            <select class="field" name="status">
                <option value="">Any</option>
                <?php foreach (['2xx' => 'Success (2xx)', '3xx' => 'Redirect (3xx)', '4xx' => 'Client error (4xx)', '5xx' => 'Server error (5xx)'] as $value => $label): ?>
                    <option value="<?= $value ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Method
            <select class="field" name="method">
                <option value="">Any</option>
                <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method): ?>
                    <option value="<?= $method ?>"<?= $filters['method'] === $method ? ' selected' : '' ?>><?= $method ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Slower than (ms)
            <input class="field" type="number" min="0" step="1" name="min_ms" value="<?= $this->e($filters['min_ms'] ?? '') ?>" style="width: 110px">
        </label>
        <button class="button primary" type="submit">Filter</button>
        <?php if (array_filter($filters, static fn ($value) => $value !== null && $value !== '') !== []): ?>
            <a class="button quiet" href="<?= $this->e($this->url(['view' => 'requests'])) ?>">Clear filters</a>
        <?php endif; ?>
    </form>

    <?php if ($data['rows'] === []): ?>
        <div class="empty">
            <strong>No requests match</strong>
            Widen the period or clear the filters.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable>
                <thead><tr>
                    <th><button type="button" data-sort>Time</button></th>
                    <th>Request</th>
                    <th><button type="button" data-sort>Status</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Duration</button></th>
                    <th>Route</th>
                </tr></thead>
                <tbody>
                <?php foreach ($data['rows'] as $row): ?>
                    <tr>
                        <td class="small secondary" data-sort-value="<?= $this->e($row['created_at']) ?>"><?= $this->time($row['created_at']) ?></td>
                        <td class="break">
                            <span class="method"><?= $this->e($row['method']) ?></span>
                            <?php if ($row['trace_id'] !== ''): ?>
                                <a class="row-link" href="<?= $this->e($this->url(['trace' => $row['trace_id'], 'view' => null])) ?>"><?= $this->e($row['url']) ?></a>
                            <?php else: ?>
                                <?= $this->e($row['url']) ?>
                            <?php endif; ?>
                        </td>
                        <td data-sort-value="<?= $this->e($row['status_code']) ?>"><?= $this->httpStatus($row['status_code']) ?></td>
                        <td class="num" data-sort-value="<?= $this->e($row['duration_ms']) ?>"><?= $this->e($this->ms($row['duration_ms'])) ?></td>
                        <td class="small secondary break"><?= $this->e($row['route']) ?><?php if (count($scope['services']) > 1): ?><br><span class="muted"><?= $this->e($row['service']) ?></span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <nav class="pager" aria-label="Pages">
            <span><?= $this->e($this->count($first)) ?>–<?= $this->e($this->count($last)) ?> of <?= $this->e($this->count($data['total'])) ?></span>
            <span class="actions">
                <?php $base = ['view' => 'requests', 'q' => $filters['search'], 'status' => $filters['status'], 'method' => $filters['method'], 'min_ms' => $filters['min_ms']]; ?>
                <?php if ($data['page'] > 1): ?><a class="button" href="<?= $this->e($this->url($base + ['page' => $data['page'] - 1])) ?>">Newer</a><?php else: ?><button class="button" disabled>Newer</button><?php endif; ?>
                <?php if ($data['page'] < $pages): ?><a class="button" href="<?= $this->e($this->url($base + ['page' => $data['page'] + 1])) ?>">Older</a><?php else: ?><button class="button" disabled>Older</button><?php endif; ?>
            </span>
        </nav>
    <?php endif; ?>
</section>
