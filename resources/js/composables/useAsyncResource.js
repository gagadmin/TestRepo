import { computed, ref, shallowRef } from 'vue';
import { ApiError } from '@/services/http';

/**
 * Wraps an async fetcher in the loading / error / data lifecycle.
 *
 * The original App.vue repeated this exact shape fifteen times:
 *
 *   xLoading.value = true;
 *   xError.value = '';
 *   try { const { data } = await axios.get(...); x.value = data; }
 *   catch (e) { xError.value = e.response?.status === 403 ? '...' : '...'; }
 *   finally { xLoading.value = false; }
 *
 * Each copy drifted slightly, so error precedence and 403 handling were
 * inconsistent between views. This composable is the single implementation.
 *
 * @template T
 * @param {(...args: any[]) => Promise<T>} fetcher
 * @param {object}  [options]
 * @param {T}       [options.initialValue]  Value before the first successful load.
 * @param {boolean} [options.keepPreviousOnError]  Retain last good data when a
 *        refresh fails, so a transient failure does not blank the screen.
 * @param {(value: T) => void} [options.onSuccess]
 */
export function useAsyncResource(fetcher, options = {}) {
    const {
        initialValue = null,
        keepPreviousOnError = true,
        onSuccess = null,
    } = options;

    // shallowRef: API payloads are replaced wholesale, never mutated deeply.
    // Deep reactivity on large nested payloads is wasted work.
    const data = shallowRef(initialValue);
    const error = ref('');
    const loading = ref(false);
    const hasLoaded = ref(false);

    /** Guards against an older in-flight request overwriting a newer one. */
    let requestToken = 0;

    async function execute(...args) {
        const token = ++requestToken;
        loading.value = true;
        error.value = '';

        try {
            const result = await fetcher(...args);

            // A newer call started while this one was in flight — discard.
            if (token !== requestToken) {
                return data.value;
            }

            data.value = result;
            hasLoaded.value = true;
            onSuccess?.(result);

            return result;
        } catch (caught) {
            if (token !== requestToken) {
                return data.value;
            }

            error.value = caught instanceof ApiError
                ? caught.message
                : caught?.message ?? 'Something went wrong.';

            if (!keepPreviousOnError) {
                data.value = initialValue;
            }

            return null;
        } finally {
            if (token === requestToken) {
                loading.value = false;
            }
        }
    }

    function reset() {
        requestToken++;
        data.value = initialValue;
        error.value = '';
        loading.value = false;
        hasLoaded.value = false;
    }

    return {
        data,
        error,
        loading,
        hasLoaded,
        execute,
        reset,
        clearError: () => { error.value = ''; },
        /** True on the very first load, when there is nothing to show yet. */
        isInitialLoading: computed(() => loading.value && !hasLoaded.value),
        isEmpty: computed(() => hasLoaded.value && !loading.value && !data.value),
    };
}

/**
 * Wraps a write operation (create/update/delete) in saving / error state.
 *
 * @param {(...args: any[]) => Promise<any>} action
 */
export function useAsyncAction(action, { onSuccess = null } = {}) {
    const saving = ref(false);
    const error = ref('');

    async function execute(...args) {
        saving.value = true;
        error.value = '';

        try {
            const result = await action(...args);
            await onSuccess?.(result);

            return { ok: true, result };
        } catch (caught) {
            error.value = caught instanceof ApiError
                ? caught.message
                : caught?.message ?? 'The action could not be completed.';

            return { ok: false, error: error.value };
        } finally {
            saving.value = false;
        }
    }

    return {
        saving,
        error,
        execute,
        clearError: () => { error.value = ''; },
    };
}
