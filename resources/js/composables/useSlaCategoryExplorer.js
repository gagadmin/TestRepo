import { computed, ref } from 'vue';

const LEVELS = [
    { label: 'Category', value: 'category' },
    { label: 'Sub Category', value: 'sub_category' },
    { label: 'Item', value: 'item' },
];

/**
 * Cascading Category -> Sub Category -> Item filtering over the flat SLA rows.
 *
 * All aggregation happens client-side against the payload already fetched, so
 * changing a filter costs no request. Extracted from App.vue where it was ten
 * loose computed properties plus two mutating change handlers.
 *
 * @param {import('vue').Ref<object|null>} analytics Freshservice analytics payload.
 */
export function useSlaCategoryExplorer(analytics) {
    const filters = ref({ category: null, sub_category: null, item: null });
    const level = ref('category');

    const flat = computed(() => analytics.value?.sla_by_category?.flat ?? []);

    const scoped = computed(() => {
        const { category, sub_category: subCategory, item } = filters.value;

        return flat.value.filter((row) => (
            (!category || row.category === category)
            && (!subCategory || row.sub_category === subCategory)
            && (!item || row.item === item)
        ));
    });

    const sortedUnique = (values) => [...new Set(values)]
        .sort((a, b) => String(a).localeCompare(String(b)))
        .map((value) => ({ label: value, value }));

    const categoryOptions = computed(() => sortedUnique(flat.value.map((row) => row.category)));

    const subCategoryOptions = computed(() => {
        const { category } = filters.value;
        const rows = category
            ? flat.value.filter((row) => row.category === category)
            : flat.value;

        return sortedUnique(rows.map((row) => row.sub_category));
    });

    const itemOptions = computed(() => {
        const { category, sub_category: subCategory } = filters.value;
        const rows = flat.value.filter((row) => (
            (!category || row.category === category)
            && (!subCategory || row.sub_category === subCategory)
        ));

        return sortedUnique(rows.map((row) => row.item));
    });

    /** Aggregate the scoped rows at whichever level is selected. */
    const chartRows = computed(() => {
        const buckets = new Map();

        scoped.value.forEach((row) => {
            let label;

            if (level.value === 'category') {
                label = row.category;
            } else if (level.value === 'sub_category') {
                label = filters.value.category
                    ? row.sub_category
                    : `${row.category} · ${row.sub_category}`;
            } else {
                label = filters.value.sub_category
                    ? row.item
                    : `${row.sub_category} · ${row.item}`;
            }

            const bucket = buckets.get(label) ?? { label, breached: 0, total: 0 };
            bucket.breached += Number(row.breached ?? 0);
            bucket.total += Number(row.total ?? 0);
            buckets.set(label, bucket);
        });

        return [...buckets.values()]
            .map((bucket) => ({
                ...bucket,
                compliant: bucket.total - bucket.breached,
                compliance: bucket.total > 0
                    ? Math.round(((bucket.total - bucket.breached) / bucket.total) * 1000) / 10
                    : 100,
            }))
            .sort((a, b) => b.breached - a.breached || b.total - a.total)
            .slice(0, 25);
    });

    const totals = computed(() => {
        const total = scoped.value.reduce((sum, row) => sum + Number(row.total ?? 0), 0);
        const breached = scoped.value.reduce((sum, row) => sum + Number(row.breached ?? 0), 0);

        return {
            total,
            breached,
            compliant: total - breached,
            compliance: total > 0
                ? Math.round(((total - breached) / total) * 1000) / 10
                : 100,
        };
    });

    /** Selecting a broader level clears the narrower selections below it. */
    function onCategoryChange() {
        filters.value.sub_category = null;
        filters.value.item = null;

        if (filters.value.category && level.value === 'category') {
            level.value = 'sub_category';
        }
    }

    function onSubCategoryChange() {
        filters.value.item = null;

        if (filters.value.sub_category && level.value !== 'item') {
            level.value = 'item';
        }
    }

    function reset() {
        filters.value = { category: null, sub_category: null, item: null };
        level.value = 'category';
    }

    const activeLevelLabel = computed(
        () => LEVELS.find((option) => option.value === level.value)?.label ?? 'Category',
    );

    return {
        filters,
        level,
        levelOptions: LEVELS,
        activeLevelLabel,
        categoryOptions,
        subCategoryOptions,
        itemOptions,
        chartRows,
        totals,
        onCategoryChange,
        onSubCategoryChange,
        reset,
    };
}

export default useSlaCategoryExplorer;
