<?php

declare(strict_types=1);

/** @var \PulsePHP\Dashboard\View $this */
/** @var array<string, mixed> $page */
/** @var array<string, mixed> $data */

$scope = $this->scope();
$screen = $page['screen'];
$activeNav = match ($screen) {
    'error' => 'errors',
    'trace' => 'requests',
    default => $screen,
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= $this->e($page['title']) ?> — PulsePHP</title>
    <script>try { const theme = localStorage.getItem('pulse-theme'); if (theme === 'light' || theme === 'dark') document.documentElement.dataset.theme = theme; } catch (_) {}</script>
    <link rel="stylesheet" href="<?= $this->e($this->asset('app.css', $page['assetVersion'])) ?>">
</head>
<body x-data="pulseShell(<?= $this->e($this->json($page['palette'])) ?>)" @keydown.window="onKey($event)">
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="<?= $this->e($this->url(['view' => 'overview'])) ?>" aria-label="PulsePHP overview">
            <svg width="30" height="20" viewBox="0 0 30 20" aria-hidden="true"><path d="M1 11h7l2.5-6 4 13 3.5-10 2 3H29" fill="none" stroke="var(--trace)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="brand-name">Pulse<span>PHP</span></span>
        </a>
        <nav class="nav" aria-label="Screens">
            <?php foreach ($page['nav'] as $key => $label): ?>
                <?php if ($key === 'settings'): ?><div class="nav-divider" role="presentation"></div><?php endif; ?>
                <a href="<?= $this->e($this->url(['view' => $key])) ?>" <?= $activeNav === $key ? 'aria-current="page"' : '' ?>>
                    <span><?= $this->e($label) ?></span>
                    <?php if ($key === 'errors' && $page['openErrors'] > 0): ?><span class="nav-count" title="Open error groups"><?= $this->e($page['openErrors']) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <p class="sidebar-foot">Data stays in your SQLite file.</p>
    </aside>

    <div class="main">
        <header class="topbar">
            <form class="scope" method="get" aria-label="Scope">
                <?php if (!in_array($screen, ['overview', 'trace'], true)): ?><input type="hidden" name="view" value="<?= $this->e($screen === 'error' ? 'errors' : $screen) ?>"><?php endif; ?>
                <label class="visually-hidden" for="scope-period">Period</label>
                <select id="scope-period" class="field" name="period" onchange="this.form.requestSubmit()">
                    <?php foreach ($scope['periods'] as $key => $label): ?>
                        <option value="<?= $this->e($key) ?>"<?= $scope['period'] === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="scope-service">Service</label>
                <select id="scope-service" class="field" name="service" onchange="this.form.requestSubmit()">
                    <option value="">All services</option>
                    <?php foreach ($scope['services'] as $service): ?>
                        <option value="<?= $this->e($service) ?>"<?= $scope['service'] === $service ? ' selected' : '' ?>><?= $this->e($service) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="visually-hidden" for="scope-route">Route</label>
                <select id="scope-route" class="field" name="route" onchange="this.form.requestSubmit()">
                    <option value="">All routes</option>
                    <?php foreach ($scope['routes'] as $route): ?>
                        <option value="<?= $this->e($route) ?>"<?= $scope['route'] === $route ? ' selected' : '' ?>><?= $this->e($route) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="button" type="submit">Apply</button></noscript>
            </form>
            <div class="topbar-actions">
                <button type="button" class="button quiet" @click="openPalette()" aria-haspopup="dialog">Search <kbd>Ctrl K</kbd></button>
                <button type="button" class="button quiet" @click="cycleTheme()" :aria-label="'Theme: ' + themeLabel()" x-text="themeLabel()">Theme</button>
            </div>
        </header>

        <main class="content" id="content">
            <?= $this->render('screens/' . $screen, ['data' => $data, 'page' => $page]) ?>
        </main>
    </div>
</div>

<div class="palette-backdrop" x-show="paletteOpen" x-cloak @click.self="closePalette()" role="dialog" aria-modal="true" aria-label="Search">
    <div class="palette">
        <label class="visually-hidden" for="palette-input">Search screens, routes and errors</label>
        <input id="palette-input" type="text" placeholder="Jump to a screen, route, service or error" autocomplete="off"
               x-ref="paletteInput" x-model="query" @input="selected = 0"
               @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go()" @keydown.escape="closePalette()">
        <ul role="listbox" aria-label="Results">
            <template x-for="(item, index) in results()" :key="item.kind + item.url">
                <li><a :href="item.url" role="option" :aria-selected="index === selected" @mouseenter="selected = index">
                    <span class="break" x-text="item.label"></span><span class="palette-kind" x-text="item.kind"></span>
                </a></li>
            </template>
        </ul>
        <p class="palette-empty" x-show="results().length === 0">Nothing matches. Try a route path or an exception class.</p>
    </div>
</div>

<script defer src="<?= $this->e($this->asset('chart.js', $page['assetVersion'])) ?>"></script>
<script defer src="<?= $this->e($this->asset('app.js', $page['assetVersion'])) ?>"></script>
<script defer src="<?= $this->e($this->asset('alpine.js', $page['assetVersion'])) ?>"></script>
</body>
</html>
