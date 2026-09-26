<div class="flex flex-wrap items-center gap-1 text-xs">
    <div class="inline-flex border border-[#dce3de] bg-white" role="group" aria-label="Widget visualization">
        <button type="button" @click="setView('<?= $escape($widgetId) ?>', 'chart')" :aria-pressed="currentView('<?= $escape($widgetId) ?>') === 'chart'" class="px-2 py-1.5" :class="currentView('<?= $escape($widgetId) ?>') === 'chart' ? 'bg-[#123b32] text-white' : 'text-[#52645a]'">Graph</button>
        <button type="button" @click="setView('<?= $escape($widgetId) ?>', 'table')" :aria-pressed="currentView('<?= $escape($widgetId) ?>') === 'table'" class="border-l border-[#dce3de] px-2 py-1.5" :class="currentView('<?= $escape($widgetId) ?>') === 'table' ? 'bg-[#123b32] text-white' : 'text-[#52645a]'">Table</button>
    </div>
    <button type="button" @click="moveWidget('<?= $escape($widgetId) ?>', -1)" aria-label="Move widget up" title="Move up" class="h-8 w-8 border border-[#dce3de] text-[#52645a]">↑</button>
    <button type="button" @click="moveWidget('<?= $escape($widgetId) ?>', 1)" aria-label="Move widget down" title="Move down" class="h-8 w-8 border border-[#dce3de] text-[#52645a]">↓</button>
    <button type="button" @click="toggleCollapsed('<?= $escape($widgetId) ?>')" :aria-expanded="!isCollapsed('<?= $escape($widgetId) ?>')" class="h-8 border border-[#dce3de] px-2 text-[#52645a]" x-text="isCollapsed('<?= $escape($widgetId) ?>') ? 'Expand' : 'Collapse'"></button>
    <button type="button" @click="setVisible('<?= $escape($widgetId) ?>', false)" aria-label="Hide widget" title="Hide widget" class="h-8 w-8 border border-[#dce3de] text-[#52645a]">×</button>
</div>