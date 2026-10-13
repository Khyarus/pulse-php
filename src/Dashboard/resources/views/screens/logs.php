<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $data */

$scope = $this->scope();
$levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
$tone = static fn (string $level): string => match ($level) {
    'emergency', 'alert', 'critical', 'error' => 'crit',
    'warning' => 'warn',
    'notice', 'info' => 'info',
    default => '',
};
?>
<div class="page-head">
    <div>
        <h1>Logs</h1>
        <p>Log lines linked to the request or job that wrote them.</p>
    </div>
</div>

<section class="panel">
    <form class="filters" method="get" style="padding-top: 16px" aria-label="Filter logs">
        <input type="hidden" name="view" value="logs">
        <input type="hidden" name="period" value="<?= $this->e($scope['period']) ?>">
        <?php if ($scope['service'] !== null): ?><input type="hidden" name="service" value="<?= $this->e($scope['service']) ?>"><?php endif; ?>
        <?php if ($scope['route'] !== null): ?><input type="hidden" name="route" value="<?= $this->e($scope['route']) ?>"><?php endif; ?>
        <label>Message contains
            <input class="field" type="search" name="q" value="<?= $this->e($data['search'] ?? '') ?>" size="28">
        </label>
        <label>Level
            <select class="field" name="level">
                <option value="">Any</option>
                <?php foreach ($levels as $level): ?>
                    <option value="<?= $level ?>"<?= $data['level'] === $level ? ' selected' : '' ?>><?= ucfirst($level) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button primary" type="submit">Filter</button>
    </form>

    <?php if ($data['logs'] === []): ?>
        <div class="empty">
            <strong>No log lines</strong>
            In Laravel, warnings and above are captured when <code>PULSE_COLLECT_LOGS</code> is on. In plain PHP, call <code>Pulse::getInstance()->recordLog()</code> or add <code>PulseLogHandler</code> to Monolog.
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>When</th><th>Level</th><th>Message</th><th>Route</th></tr></thead>
                <tbody>
                <?php foreach ($data['logs'] as $log): ?>
                    <tr>
                        <td class="small secondary" style="white-space: nowrap"><?= $this->time($log['created_at']) ?></td>
                        <td><span class="status" data-tone="<?= $tone($log['level']) ?>"><?= $this->e(ucfirst($log['level'])) ?></span></td>
                        <td class="break"><?= $this->e($log['message']) ?>
                            <?php if ($log['context'] !== '{}' && $log['context'] !== '[]'): ?><details><summary class="small muted">Context</summary><pre class="code small break" style="white-space: pre-wrap; margin: 6px 0 0"><?= $this->e($log['context']) ?></pre></details><?php endif; ?></td>
                        <td class="small secondary break">
                            <?php if ($log['trace_id'] !== ''): ?><a class="row-link" href="<?= $this->e($this->url(['trace' => $log['trace_id'], 'view' => null])) ?>"><?= $this->e($log['route']) ?></a><?php else: ?><?= $this->e($log['route']) ?><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
