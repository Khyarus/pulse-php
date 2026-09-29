<section class="panel min-w-0 p-4 sm:p-5" data-widget="outbound" x-show="isVisible('outbound')" :style="{ order: widgetOrder('outbound') }">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-sm font-semibold">Outbound API calls</h2><p class="mt-1 text-xs text-[#718078]">Laravel HTTP client requests and failures</p></div>
        <?php $widgetId = 'outbound';
        $widgetLabel = 'Outbound APIs';
        require __DIR__ . '/_controls.php'; ?>
    </div>
    <div x-show="!isCollapsed('outbound')">
        <div x-show="currentView('outbound') === 'chart'" class="h-[260px]"><canvas id="outbound-chart" aria-label="Outbound API response durations" role="img"></canvas></div>
        <div x-show="currentView('outbound') === 'table'" class="scroll-table overflow-x-auto" data-table-widget>
            <table class="w-full min-w-[760px] text-left text-xs" data-sortable>
                <thead class="bg-[#f8faf8] text-[10px] uppercase text-[#77857d]"><tr>
                    <th class="px-3 py-2"><button data-sort-column="0">Time</button></th><th class="px-3 py-2"><button data-sort-column="1">Method</button></th>
                    <th class="px-3 py-2"><button data-sort-column="2">Destination</button></th><th class="px-3 py-2"><button data-sort-column="3" data-sort-type="number">Status</button></th>
                    <th class="px-3 py-2"><button data-sort-column="4" data-sort-type="number">Duration</button></th><th class="px-3 py-2"><button data-sort-column="5">Service</button></th><th class="px-3 py-2"><button data-sort-column="6">Route</button></th>
                </tr></thead>
                <tbody class="divide-y divide-[#edf0ee]">
                    <?php foreach ($outboundRequests as $request): ?><tr>
                        <td class="mono whitespace-nowrap px-3 py-2"><?= $escape($request['created_at']) ?></td><td class="px-3 py-2"><?= $escape($request['method']) ?></td>
                        <td class="max-w-72 break-all px-3 py-2"><?= $escape($request['url']) ?></td><td class="mono px-3 py-2"><?= $escape($request['status_code']) ?></td>
                        <td class="mono px-3 py-2" data-sort-value="<?= $escape($request['duration_ms']) ?>"><?= $escape(number_format($request['duration_ms'], 2)) ?> ms</td>
                        <td class="px-3 py-2"><?= $escape($request['service']) ?></td><td class="px-3 py-2"><?= $escape($request['route']) ?></td>
                    </tr><?php endforeach; ?>
                    <?php if ($outboundRequests === []): ?><tr><td colspan="7" class="px-3 py-8 text-center text-[#89968f]">No outbound API calls in this scope.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <nav class="table-pager mt-3 flex items-center justify-end gap-3 text-xs text-[#52645a]" data-table-pager aria-label="Outbound API table pages">
                <button type="button" data-prev class="border border-[#dce3de] px-2 py-1">Previous</button><span data-page-label></span><button type="button" data-next class="border border-[#dce3de] px-2 py-1">Next</button>
            </nav>
        </div>
    </div>
</section>