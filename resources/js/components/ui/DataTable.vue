<script setup>
/**
 * Scrollable table shell with a sticky header and a built-in empty row.
 *
 * App.vue had fifteen `<table>` blocks that each repeated the scroll wrapper,
 * sticky-header CSS and a bespoke "no rows" cell. Columns are declared as data
 * so a caller cannot forget the empty state.
 */
const props = defineProps({
    /** [{ key, label, numeric?, width?, truncate? }] */
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: [String, Function], default: 'id' },
    emptyText: { type: String, default: 'No records to display.' },
    maxHeight: { type: String, default: '460px' },
    caption: { type: String, default: '' },
});

function keyFor(row, index) {
    if (typeof props.rowKey === 'function') return props.rowKey(row, index);

    return row?.[props.rowKey] ?? index;
}
</script>

<template>
    <div class="data-table-scroll" :style="{ maxHeight: props.maxHeight }">
        <table class="data-table">
            <caption v-if="props.caption" class="sr-only">{{ props.caption }}</caption>
            <thead>
                <tr>
                    <th
                        v-for="column in props.columns"
                        :key="column.key"
                        :class="{ numeric: column.numeric }"
                        :style="column.width ? { width: column.width } : undefined"
                        scope="col"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in props.rows" :key="keyFor(row, index)">
                    <td
                        v-for="column in props.columns"
                        :key="column.key"
                        :class="{ numeric: column.numeric, truncate: column.truncate }"
                    >
                        <!--
                          A per-column slot lets callers render tags or buttons
                          while still getting the shell's structure for free.
                        -->
                        <slot :name="`cell:${column.key}`" :row="row" :value="row?.[column.key]">
                            {{ row?.[column.key] ?? '—' }}
                        </slot>
                    </td>
                </tr>
                <tr v-if="!props.rows.length">
                    <td :colspan="props.columns.length" class="data-table-empty">
                        {{ props.emptyText }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.data-table-scroll { overflow: auto; margin-top: 4px; }
.data-table { width: 100%; border-collapse: collapse; font-size: 11px; }

.data-table th {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: 8px 10px;
    background: #fff;
    color: var(--muted);
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    text-align: left;
    border-bottom: 1px solid var(--line);
}

.data-table td {
    padding: 8px 10px;
    color: var(--ink);
    border-bottom: 1px solid var(--line);
}

.data-table tbody tr:last-child td { border-bottom: 0; }
.data-table .numeric { text-align: right; }

.data-table .truncate {
    max-width: 340px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.data-table-empty {
    padding: 18px 10px !important;
    color: var(--muted);
    text-align: center;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
