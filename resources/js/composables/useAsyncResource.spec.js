import { describe, expect, it, vi } from 'vitest';
import { useAsyncResource, useAsyncAction } from './useAsyncResource';
import { ApiError } from '@/services/http';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('useAsyncResource', () => {
    it('exposes data on success and clears loading', async () => {
        const resource = useAsyncResource(async () => ({ total: 3 }));

        expect(resource.loading.value).toBe(false);
        expect(resource.hasLoaded.value).toBe(false);

        const promise = resource.execute();
        expect(resource.loading.value).toBe(true);

        await promise;

        expect(resource.loading.value).toBe(false);
        expect(resource.hasLoaded.value).toBe(true);
        expect(resource.data.value).toEqual({ total: 3 });
        expect(resource.error.value).toBe('');
    });

    it('surfaces the ApiError message and keeps previous data by default', async () => {
        let shouldFail = false;
        const resource = useAsyncResource(async () => {
            if (shouldFail) {
                throw new ApiError('Security data is restricted.', { status: 403 });
            }

            return { ok: true };
        });

        await resource.execute();
        expect(resource.data.value).toEqual({ ok: true });

        shouldFail = true;
        await resource.execute();

        expect(resource.error.value).toBe('Security data is restricted.');
        // A failed refresh must not blank a screen the user was reading.
        expect(resource.data.value).toEqual({ ok: true });
    });

    it('discards previous data when keepPreviousOnError is false', async () => {
        let shouldFail = false;
        const resource = useAsyncResource(
            async () => {
                if (shouldFail) throw new ApiError('gone', { status: 500 });

                return 'value';
            },
            { initialValue: null, keepPreviousOnError: false },
        );

        await resource.execute();
        shouldFail = true;
        await resource.execute();

        expect(resource.data.value).toBeNull();
    });

    it('ignores a stale response when a newer request has started', async () => {
        const delays = [30, 0];
        let call = 0;

        const resource = useAsyncResource(async () => {
            const index = call++;
            await new Promise((resolve) => setTimeout(resolve, delays[index]));

            return index === 0 ? 'stale' : 'fresh';
        });

        // Start the slow request, then immediately start the fast one.
        const first = resource.execute();
        const second = resource.execute();

        await Promise.all([first, second]);
        await flush();

        // The slow first response must not overwrite the newer result.
        expect(resource.data.value).toBe('fresh');
    });

    it('reports isInitialLoading only before the first success', async () => {
        const resource = useAsyncResource(async () => 'ok');

        const promise = resource.execute();
        expect(resource.isInitialLoading.value).toBe(true);

        await promise;

        const second = resource.execute();
        expect(resource.isInitialLoading.value).toBe(false);
        await second;
    });

    it('reset returns to the initial state', async () => {
        const resource = useAsyncResource(async () => 'ok', { initialValue: 'start' });

        await resource.execute();
        resource.reset();

        expect(resource.data.value).toBe('start');
        expect(resource.hasLoaded.value).toBe(false);
        expect(resource.error.value).toBe('');
    });
});

describe('useAsyncAction', () => {
    it('reports ok and runs onSuccess', async () => {
        const onSuccess = vi.fn();
        const action = useAsyncAction(async (value) => value * 2, { onSuccess });

        const result = await action.execute(4);

        expect(result).toEqual({ ok: true, result: 8 });
        expect(onSuccess).toHaveBeenCalledWith(8);
        expect(action.saving.value).toBe(false);
    });

    it('captures the failure message without throwing', async () => {
        const action = useAsyncAction(async () => {
            throw new ApiError('Explain how this finding was resolved.', { status: 422 });
        });

        const result = await action.execute();

        expect(result.ok).toBe(false);
        expect(action.error.value).toBe('Explain how this finding was resolved.');
        expect(action.saving.value).toBe(false);
    });
});
