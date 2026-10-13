<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */

$scope = $this->scope();
$tabs = ['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored', 'all' => 'All'];
?>
<div class="page-head">
    <div>
        <h1>Errors</h1>
        <p>Exceptions grouped by class and location. Resolve a group when it's fixed; it reopens as regressed if it happens again.</p>
    </div>
</div>

<section class="panel">
    <nav class="tabs" aria-label="Error status">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= $this->e($this->url(['view' => 'errors', 'status' => $key === 'open' ? null : $key])) ?>" <?= $data['status'] === $key ? 'aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($data['groups'] === []): ?>
        <div class="empty">
            <strong><?= $data['status'] === 'open' ? 'No open errors' : 'Nothing here' ?></strong>
            <?= $data['status'] === 'open' ? 'No new or regressed exceptions in the ' . $this->e(strtolower($scope['periodLabel'])) . '.' : 'No error groups with this status in the selected period.' ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table data-sortable>
                <thead><tr>
                    <th>Error</th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Events</button></th>
                    <th class="num"><button type="button" data-sort data-sort-type="number">Routes</button></th>
                    <th><button type="button" data-sort>Last seen</button></th>
                    <th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($data['groups'] as $group): ?>
                    <tr>
                        <td>
                            <a class="row-link" href="<?= $this->e($this->url(['view' => 'error', 'id' => $group['fingerprint'], 'status' => null])) ?>" title="<?= $this->e($group['class']) ?>">
                                <strong><?= $this->e($this->shortClass($group['class']) ?: 'Error') ?></strong>
                            </a>
                            <p class="small secondary truncate"><?= $this->e($group['message']) ?></p>
                            <p class="small muted break"><?= $this->e($group['file'] . ':' . $group['line']) ?></p>
                        </td>
                        <td class="num"><?= $this->e($this->count($group['occurrences'])) ?></td>
                        <td class="num"><?= $this->e($group['routes']) ?></td>
                        <td class="small secondary" data-sort-value="<?= $this->e($group['last_seen']) ?>"><?= $this->time($group['last_seen'], 'relative') ?></td>
                        <td><?= $this->errorStatus($group['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
