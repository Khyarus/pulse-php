<section class="panel min-w-0 p-4 sm:p-5 xl:col-span-2" data-widget="exceptions" x-show="isVisible('exceptions')" :style="{ order: widgetOrder('exceptions') }">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div><h2 class="text-sm font-semibold">Exceptions</h2><p class="mt-1 text-xs text-[#718078]">Grouped by class and location in the selected scope</p></div>
        <?php $widgetId = 'exceptions';
        $widgetLabel = 'Exceptions';
        require __DIR__ . '/_controls.php'; ?>
    </div>
    <div x-show="!isCollapsed('exceptions')">
        <div x-show="currentView('exceptions') === 'chart'" class="h-[260px]"><canvas id="exceptions-chart" aria-label="Exceptions over selected period" role="img"></canvas></div>
        <div x-show="currentView('exceptions') === 'table'" class="scroll-table overflow-x-auto" data-table-widget>
            <table class="w-full min-w-[860px] text-left text-xs" data-sortable>
                <thead class="bg-[#f8faf8] text-[10px] uppercase text-[#77857d]"><tr>
                    <th class="px-3 py-2"><button data-sort-column="0">Last seen</button></th><th class="px-3 py-2"><button data-sort-column="1">Exception</button></th>
                    <th class="px-3 py-2"><button data-sort-column="2">Location</button></th><th class="px-3 py-2"><button data-sort-column="3" data-sort-type="number">Count</button></th>
                    <th class="px-3 py-2"><button data-sort-column="4" data-sort-type="number">Unhandled</button></th><th class="px-3 py-2">Trace</th>
                </tr></thead>
                <tbody class="divide-y divide-[#edf0ee]">
                    <template x-for="group in liveExceptionGroups" :key="group.fingerprint">
                        <tr>
                            <td class="mono whitespace-nowrap px-3 py-2" x-text="group.last_seen"></td>
                            <td class="max-w-96 break-words px-3 py-2">
                                <p class="mono text-[11px] font-semibold" x-text="group.class || 'Error'"></p>
                                <p class="mt-1 text-[#9d482e]" x-text="group.message"></p>
                            </td>
                            <td class="mono max-w-72 break-all px-3 py-2" x-text="group.file + ':' + group.line"></td>
                            <td class="mono px-3 py-2" x-text="group.occurrences"></td>
                            <td class="mono px-3 py-2" x-text="group.unhandled"></td>
                            <td class="px-3 py-2"><a x-show="group.last_trace_id !== ''" :href="traceUrl(group.last_trace_id)" class="text-[#42c98a] underline underline-offset-2">Open</a></td>
                        </tr>
                    </template>
                    <template x-if="liveExceptionGroups.length === 0"><tr data-empty-row><td colspan="6" class="px-3 py-8 text-center text-[#89968f]">No exceptions in this scope.</td></tr></template>
                </tbody>
            </table>
            <nav class="table-pager mt-3 flex items-center justify-end gap-3 text-xs text-[#52645a]" data-table-pager aria-label="Exception table pages">
                <button type="button" data-prev class="border border-[#dce3de] px-2 py-1">Previous</button><span data-page-label></span><button type="button" data-next class="border border-[#dce3de] px-2 py-1">Next</button>
            </nav>
        </div>
    </div>
</section>
