<section class="panel min-w-0 p-4 sm:p-5" data-widget="queries" x-show="isVisible('queries')" :style="{ order: widgetOrder('queries') }">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-sm font-semibold">Slow query patterns</h2><p class="mt-1 text-xs text-[#718078]">Grouped by normalized SQL</p></div>
        <?php $widgetId = 'queries';
        $widgetLabel = 'Slow queries';
        require __DIR__ . '/_controls.php'; ?>
    </div>
    <div x-show="!isCollapsed('queries')">
        <div x-show="currentView('queries') === 'chart'" class="h-[260px]"><canvas id="queries-chart" aria-label="Slow query average durations" role="img"></canvas></div>
        <div x-show="currentView('queries') === 'table'" class="scroll-table overflow-x-auto" data-table-widget>
            <table class="w-full min-w-[760px] text-left text-xs" data-sortable>
                <thead class="bg-[#f8faf8] text-[10px] uppercase text-[#77857d]"><tr>
                    <th class="px-3 py-2"><button data-sort-column="0">Normalized SQL</button></th>
                    <th class="px-3 py-2"><button data-sort-column="1">Service</button></th>
                    <th class="px-3 py-2"><button data-sort-column="2">Route</button></th>
                    <th class="px-3 py-2"><button data-sort-column="3" data-sort-type="number">Average</button></th>
                    <th class="px-3 py-2"><button data-sort-column="4" data-sort-type="number">Peak</button></th>
                    <th class="px-3 py-2"><button data-sort-column="5" data-sort-type="number">Runs</button></th>
                </tr></thead>
                <tbody class="divide-y divide-[#edf0ee]">
                    <?php foreach ($slowQueries as $query): ?><tr>
                        <td class="max-w-80 break-words px-3 py-2"><code class="mono"><?= $escape($query['normalized_sql']) ?></code></td>
                        <td class="px-3 py-2"><?= $escape($query['service']) ?></td>
                        <td class="px-3 py-2"><?= $escape($query['route']) ?></td>
                        <td class="mono px-3 py-2" data-sort-value="<?= $escape($query['avg_duration_ms']) ?>"><?= $escape(number_format($query['avg_duration_ms'], 2)) ?> ms</td>
                        <td class="mono px-3 py-2" data-sort-value="<?= $escape($query['max_duration_ms']) ?>"><?= $escape(number_format($query['max_duration_ms'], 2)) ?> ms</td>
                        <td class="mono px-3 py-2"><?= $escape($query['executions']) ?></td>
                    </tr><?php endforeach; ?>
                    <?php if ($slowQueries === []): ?><tr><td colspan="6" class="px-3 py-8 text-center text-[#89968f]">No queries in this scope.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <nav class="table-pager mt-3 flex items-center justify-end gap-3 text-xs text-[#52645a]" data-table-pager aria-label="Query table pages">
                <button type="button" data-prev class="border border-[#dce3de] px-2 py-1">Previous</button><span data-page-label></span><button type="button" data-next class="border border-[#dce3de] px-2 py-1">Next</button>
            </nav>
        </div>
    </div>
</section>