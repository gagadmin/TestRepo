import { describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { useSlaCategoryExplorer } from './useSlaCategoryExplorer';

const analytics = ref({
    sla_by_category: {
        flat: [
            { category: 'Hardware', sub_category: 'Laptop', item: 'Battery', total: 10, breached: 4 },
            { category: 'Hardware', sub_category: 'Laptop', item: 'Screen', total: 6, breached: 1 },
            { category: 'Hardware', sub_category: 'Printer', item: 'Toner', total: 4, breached: 0 },
            { category: 'Software', sub_category: 'ERP', item: 'Access', total: 20, breached: 10 },
        ],
    },
});

describe('useSlaCategoryExplorer', () => {
    it('aggregates at category level by default', () => {
        const explorer = useSlaCategoryExplorer(analytics);

        const labels = explorer.chartRows.value.map((row) => row.label);
        expect(labels).toEqual(['Software', 'Hardware']); // sorted by breached desc

        const hardware = explorer.chartRows.value.find((row) => row.label === 'Hardware');
        expect(hardware.total).toBe(20);
        expect(hardware.breached).toBe(5);
        expect(hardware.compliant).toBe(15);
        expect(hardware.compliance).toBe(75);
    });

    it('computes totals across the scoped rows', () => {
        const explorer = useSlaCategoryExplorer(analytics);

        expect(explorer.totals.value).toEqual({
            total: 40,
            breached: 15,
            compliant: 25,
            compliance: 62.5,
        });
    });

    it('cascades options so sub category is limited by category', () => {
        const explorer = useSlaCategoryExplorer(analytics);

        expect(explorer.subCategoryOptions.value.map((o) => o.value))
            .toEqual(['ERP', 'Laptop', 'Printer']);

        explorer.filters.value.category = 'Hardware';
        expect(explorer.subCategoryOptions.value.map((o) => o.value))
            .toEqual(['Laptop', 'Printer']);
    });

    it('limits item options by the selected sub category', () => {
        const explorer = useSlaCategoryExplorer(analytics);

        explorer.filters.value.category = 'Hardware';
        explorer.filters.value.sub_category = 'Laptop';

        expect(explorer.itemOptions.value.map((o) => o.value)).toEqual(['Battery', 'Screen']);
    });

    it('selecting a category clears narrower filters and drills the level down', () => {
        const explorer = useSlaCategoryExplorer(analytics);

        explorer.filters.value.sub_category = 'Laptop';
        explorer.filters.value.item = 'Battery';
        explorer.filters.value.category = 'Hardware';
        explorer.onCategoryChange();

        expect(explorer.filters.value.sub_category).toBeNull();
        expect(explorer.filters.value.item).toBeNull();
        expect(explorer.level.value).toBe('sub_category');
    });

    it('selecting a sub category drills to item level', () => {
        const explorer = useSlaCategoryExplorer(analytics);

        explorer.filters.value.sub_category = 'Laptop';
        explorer.onSubCategoryChange();

        expect(explorer.filters.value.item).toBeNull();
        expect(explorer.level.value).toBe('item');
    });

    it('scopes rows when filtered', () => {
        const explorer = useSlaCategoryExplorer(analytics);

        explorer.filters.value.category = 'Software';

        expect(explorer.totals.value.total).toBe(20);
        expect(explorer.totals.value.breached).toBe(10);
    });

    it('reset clears filters and returns to category level', () => {
        const explorer = useSlaCategoryExplorer(analytics);

        explorer.filters.value.category = 'Hardware';
        explorer.level.value = 'item';
        explorer.reset();

        expect(explorer.filters.value).toEqual({ category: null, sub_category: null, item: null });
        expect(explorer.level.value).toBe('category');
    });

    it('reports 100% compliance for an empty set rather than dividing by zero', () => {
        const empty = ref({ sla_by_category: { flat: [] } });
        const explorer = useSlaCategoryExplorer(empty);

        expect(explorer.totals.value.compliance).toBe(100);
        expect(explorer.chartRows.value).toEqual([]);
    });

    it('tolerates a missing payload', () => {
        const explorer = useSlaCategoryExplorer(ref(null));

        expect(explorer.chartRows.value).toEqual([]);
        expect(explorer.categoryOptions.value).toEqual([]);
    });
});
