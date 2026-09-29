<section class="panel min-w-0 p-4 sm:p-5" data-widget="spans" x-show="isVisible('spans')" :style="{ order: widgetOrder('spans') }">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-sm font-semibold">Slow spans</h2><p class="mt-1 text-xs text-[#718078]">Grouped by span, service and route</p></div>
        <?php $widgetId = 'spans';
        $widgetLabel = 'Spans';
        require __DIR__ . '/_controls.php'; ?>
    </div>
    <div x-show="!isCollapsed('spans')">
        <div x-show="currentView('spans') === 'chart'" class="h-[260px]"><canvas id="spans-chart" aria-label="Span average durations" role="img"></canvas></div>
        <div x-show="currentView('spans') === 'table'" class="scroll-table overflow-x-auto" data-table-widget>
            <table class="w-full min-w-[680px] text-left text-xs" data-sortable>
                <thead class="bg-[#f8faf8] text-[10px] uppercase text-[#77857d]"><tr>
                    <th class="px-3 py-2"><button data-sort-column="0">Span</button></th>
                    <th class="px-3 py-2"><button data-sort-column="1">Service</button></th>
                    <th class="px-3 py-2"><button data-sort-column="2">Route</button></th>
                    <th class="px-3 py-2"><button data-sort-column="3" data-sort-type="number">Average</button></th>
                    <th class="px-3 py-2"><button data-sort-column="4" data-sort-type="number">Peak</button></th>
                    <th class="px-3 py-2"><button data-sort-column="5" data-sort-type="number">Runs</button></th>
                </tr></thead>
                <tbody class="divide-y divide-[#edf0ee]">
                    <?php foreach ($spans as $span): ?><tr>
                        <td class="px-3 py-2"><?= $escape($span['name']) ?></td><td class="px-3 py-2"><?= $escape($span['service']) ?></td><td class="px-3 py-2"><?= $escape($span['route']) ?></td>
                        <td class="mono px-3 py-2" data-sort-value="<?= $escape($span['avg_duration_ms']) ?>"><?= $escape(number_format($span['avg_duration_ms'], 2)) ?> ms</td>
                        <td class="mono px-3 py-2" data-sort-value="<?= $escape($span['max_duration_ms']) ?>"><?= $escape(number_format($span['max_duration_ms'], 2)) ?> ms</td><td class="mono px-3 py-2"><?= $escape($span['executions']) ?></td>
                    </tr><?php endforeach; ?>
                    <?php if ($spans === []): ?><tr><td colspan="6" class="px-3 py-8 text-center text-[#89968f]">No spans in this scope.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <nav class="table-pager mt-3 flex items-center justify-end gap-3 text-xs text-[#52645a]" data-table-pager aria-label="Span table pages">
                <button type="button" data-prev class="border border-[#dce3de] px-2 py-1">Previous</button><span data-page-label></span><button type="button" data-next class="border border-[#dce3de] px-2 py-1">Next</button>
            </nav>
        </div>
    </div>
</section>