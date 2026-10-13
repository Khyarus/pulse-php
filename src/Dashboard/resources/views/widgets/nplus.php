<section class="panel min-w-0 p-4 sm:p-5 xl:col-span-2" data-widget="nplus" x-show="isVisible('nplus')" :style="{ order: widgetOrder('nplus') }">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-sm font-semibold">Possible N+1 queries</h2><p class="mt-1 text-xs text-[#718078]">Query patterns repeated many times inside a single request or job</p></div>
        <?php $widgetId = 'nplus';
        $widgetLabel = 'N+1 queries';
        $widgetHasChart = false;
        require __DIR__ . '/_controls.php';
        $widgetHasChart = true; ?>
    </div>
    <div x-show="!isCollapsed('nplus')">
        <div class="scroll-table overflow-x-auto" data-table-widget>
            <table class="w-full min-w-[760px] text-left text-xs" data-sortable>
                <thead class="bg-[#f8faf8] text-[10px] uppercase text-[#77857d]"><tr>
                    <th class="px-3 py-2"><button data-sort-column="0">Normalized SQL</button></th>
                    <th class="px-3 py-2"><button data-sort-column="1">Route</button></th>
                    <th class="px-3 py-2"><button data-sort-column="2" data-sort-type="number">Max / trace</button></th>
                    <th class="px-3 py-2"><button data-sort-column="3" data-sort-type="number">Avg / trace</button></th>
                    <th class="px-3 py-2"><button data-sort-column="4" data-sort-type="number">Traces</button></th>
                    <th class="px-3 py-2">Example</th>
                </tr></thead>
                <tbody class="divide-y divide-[#edf0ee]">
                    <?php foreach ($nPlusOne as $pattern): ?><tr>
                        <td class="max-w-96 break-words px-3 py-2"><code class="mono"><?= $escape($pattern['normalized_sql']) ?></code></td>
                        <td class="px-3 py-2"><?= $escape($pattern['route']) ?><span class="block text-[#89968f]"><?= $escape($pattern['service']) ?></span></td>
                        <td class="mono px-3 py-2"><?= $escape($pattern['max_per_trace']) ?></td>
                        <td class="mono px-3 py-2" data-sort-value="<?= $escape($pattern['avg_per_trace']) ?>"><?= $escape(number_format($pattern['avg_per_trace'], 1)) ?></td>
                        <td class="mono px-3 py-2"><?= $escape($pattern['affected_traces']) ?></td>
                        <td class="px-3 py-2"><a class="text-[#42c98a] underline underline-offset-2" href="?trace=<?= $escape(rawurlencode($pattern['sample_trace_id'])) ?>">Open</a></td>
                    </tr><?php endforeach; ?>
                    <?php if ($nPlusOne === []): ?><tr><td colspan="6" class="px-3 py-8 text-center text-[#89968f]">No repeated query patterns in this scope.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <nav class="table-pager mt-3 flex items-center justify-end gap-3 text-xs text-[#52645a]" data-table-pager aria-label="N+1 table pages">
                <button type="button" data-prev class="border border-[#dce3de] px-2 py-1">Previous</button><span data-page-label></span><button type="button" data-next class="border border-[#dce3de] px-2 py-1">Next</button>
            </nav>
        </div>
    </div>
</section>
