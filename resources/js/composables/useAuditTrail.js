import { computed, reactive, ref } from 'vue';
import { auditService } from '@/services/auditService';
import { useAsyncResource } from './useAsyncResource';

function emptyFilters() {
    return { event: '', date_from: '', date_to: '' };
}

/**
 * Audit trail state: paging and filtering over governance evidence.
 *
 * The page renders; everything that decides *what* to fetch lives here, so the
 * paging rules can be tested without mounting a table.
 */
export function useAuditTrail() {
    const page = ref(1);
    const filters = reactive(emptyFilters());

    const trail = useAsyncResource(
        () => auditService.list(page.value, { ...filters }),
        {
            initialValue: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } },
        },
    );

    async function load(requested = 1) {
        // Clamp rather than trust the caller: the paging buttons are disabled at
        // the ends, but a stale click or a repeated key press can still ask for
        // page zero.
        page.value = Math.max(1, requested);

        return trail.execute();
    }

    /** Applying a filter always returns to the first page. */
    async function applyFilters() {
        return load(1);
    }

    async function clearFilters() {
        Object.assign(filters, emptyFilters());

        return load(1);
    }

    const events = computed(() => trail.data.value?.data ?? []);
    const meta = computed(() => trail.data.value?.meta ?? { current_page: 1, last_page: 1, total: 0 });
    const total = computed(() => meta.value.total ?? 0);
    const currentPage = computed(() => meta.value.current_page ?? 1);
    const lastPage = computed(() => meta.value.last_page ?? 1);
    const hasPrevious = computed(() => currentPage.value > 1);
    const hasNext = computed(() => currentPage.value < lastPage.value);
    const isFiltered = computed(
        () => Object.values(filters).some((value) => value !== ''),
    );

    async function previous() {
        if (hasPrevious.value) {
            return load(currentPage.value - 1);
        }
    }

    async function next() {
        if (hasNext.value) {
            return load(currentPage.value + 1);
        }
    }

    return {
        trail,
        loading: trail.loading,
        isInitialLoading: trail.isInitialLoading,
        error: trail.error,
        clearError: trail.clearError,

        filters,
        applyFilters,
        clearFilters,
        isFiltered,

        load,
        previous,
        next,

        events,
        meta,
        total,
        currentPage,
        lastPage,
        hasPrevious,
        hasNext,
    };
}

export default useAuditTrail;
