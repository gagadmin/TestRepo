import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAiTools } from './useAiTools';
import { aiToolService } from '@/services/aiToolService';

vi.mock('@/services/aiToolService', () => ({
    aiToolService: {
        list: vi.fn(),
        failures: vi.fn(),
        corrections: vi.fn(),
        create: vi.fn(),
        update: vi.fn(),
        toggle: vi.fn(),
        destroy: vi.fn(),
        resolveFailure: vi.fn(),
        reviewCorrection: vi.fn(),
    },
}));

/**
 * AI tools admin behaviour.
 *
 * The interesting logic is the three-way handler split. A reporting tool needs
 * source types; a standalone tool that reuses an application AI provider needs
 * only a model; a standalone tool with its own search API needs an endpoint,
 * allowed hosts, and a key unless one is already stored. `canSave` and the
 * request payload both branch on that, and a mistake either way means an
 * administrator either cannot save a valid tool or saves an unusable one.
 */
describe('useAiTools', () => {
    const HANDLERS = [
        { value: 'generic_http', label: 'Reporting', standalone: false },
        { value: 'web_search', label: 'Search API', standalone: true },
        { value: 'openai_web_search', label: 'OpenAI web search', standalone: true, uses_ai_provider: true },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
        aiToolService.list.mockResolvedValue({
            data: [],
            handlers: HANDLERS,
            source_types: ['erp', 'crm'],
            uncovered_sources: [],
            meta: {},
        });
        aiToolService.failures.mockResolvedValue([]);
        aiToolService.corrections.mockResolvedValue({ data: [], pending_count: 0 });
        aiToolService.create.mockResolvedValue({});
        aiToolService.update.mockResolvedValue({});
    });

    async function mounted() {
        const tools = useAiTools();
        await tools.loadAll();

        return tools;
    }

    /* ---- canSave ---- */

    it('requires at least one source type for a reporting tool', async () => {
        const t = await mounted();
        t.form.handler = 'generic_http';

        expect(t.canSave.value).toBe(false);

        t.form.source_types = ['erp'];
        expect(t.canSave.value).toBe(true);
    });

    it('requires only a model for a tool that reuses an application AI provider', async () => {
        const t = await mounted();
        t.form.handler = 'openai_web_search';

        expect(t.usesAiProvider.value).toBe(true);
        expect(t.canSave.value).toBe(false);

        t.form.options.model = 'gpt-4o';
        expect(t.canSave.value).toBe(true);
    });

    it('requires an endpoint, hosts, and a key for a search-API tool', async () => {
        const t = await mounted();
        t.form.handler = 'web_search';

        t.form.options.endpoint = 'https://search.example.test/v1';
        expect(t.canSave.value).toBe(false);

        t.form.options.allowed_hosts = 'search.example.test';
        expect(t.canSave.value).toBe(false);

        t.form.api_key = 'secret';
        expect(t.canSave.value).toBe(true);
    });

    it('accepts a stored key in place of a newly typed one', async () => {
        // On edit the key is never sent back to the browser, so requiring one
        // would block every save of an already-configured tool.
        const t = await mounted();
        t.form.handler = 'web_search';
        t.form.options.endpoint = 'https://search.example.test/v1';
        t.form.options.allowed_hosts = 'search.example.test';

        expect(t.canSave.value).toBe(false);

        t.hasStoredApiKey.value = true;
        expect(t.canSave.value).toBe(true);
    });

    /* ---- Payload ---- */

    it('sends source types and no provider options for a reporting tool', async () => {
        const t = await mounted();
        t.form.handler = 'generic_http';
        t.form.name = 'erp_reporting';
        t.form.source_types = ['erp'];

        await t.save.execute();

        const [payload] = aiToolService.create.mock.calls[0];
        expect(payload.source_types).toEqual(['erp']);
        expect(payload.options).toBeUndefined();
        expect(payload.api_key).toBeUndefined();
    });

    it('clears source types and sends provider options for a standalone tool', async () => {
        // A standalone tool answers without a connected source; leaving source
        // types attached would imply coverage it does not have.
        const t = await mounted();
        t.form.handler = 'web_search';
        t.form.source_types = ['erp'];
        t.form.options.endpoint = 'https://search.example.test/v1';
        t.form.api_key = 'secret';

        await t.save.execute();

        const [payload] = aiToolService.create.mock.calls[0];
        expect(payload.source_types).toEqual([]);
        expect(payload.options.endpoint).toBe('https://search.example.test/v1');
        expect(payload.api_key).toBe('secret');
    });

    it('omits a blank key so the stored one survives an edit', async () => {
        const t = await mounted();
        t.openEdit({
            id: 12,
            name: 'search',
            label: 'Search',
            description: '',
            handler: 'web_search',
            source_types: [],
            is_enabled: true,
            sort_order: 10,
            has_api_key: true,
            provider: { endpoint: 'https://search.example.test/v1', allowed_hosts: ['a.test'] },
        });

        await t.save.execute();

        expect(aiToolService.update).toHaveBeenCalledWith(12, expect.anything());
        const [, payload] = aiToolService.update.mock.calls[0];
        expect('api_key' in payload).toBe(false);
    });

    /* ---- Edit mapping ---- */

    it('maps a stored tool onto the form without leaking the key', async () => {
        const t = await mounted();
        t.openEdit({
            id: 3,
            name: 'search',
            label: 'Search',
            description: 'Web search',
            handler: 'web_search',
            source_types: [],
            is_enabled: false,
            sort_order: 20,
            has_api_key: true,
            provider: { endpoint: 'https://s.test', allowed_hosts: ['a.test', 'b.test'] },
        });

        expect(t.editingId.value).toBe(3);
        expect(t.form.api_key).toBe('');
        expect(t.hasStoredApiKey.value).toBe(true);
        // Held as text in the form; the API accepts either shape.
        expect(t.form.options.allowed_hosts).toBe('a.test, b.test');
        expect(t.dialogOpen.value).toBe(true);
    });

    it('resets the form when switching from edit to create', async () => {
        const t = await mounted();
        t.openEdit({
            id: 3,
            name: 'search',
            label: 'Search',
            description: 'Web search',
            handler: 'web_search',
            source_types: [],
            is_enabled: false,
            sort_order: 20,
            has_api_key: true,
            provider: { endpoint: 'https://s.test', allowed_hosts: ['a.test'] },
        });

        t.openCreate();

        expect(t.editingId.value).toBeNull();
        expect(t.form.name).toBe('');
        expect(t.form.handler).toBe('generic_http');
        expect(t.form.options.endpoint).toBe('');
        expect(t.hasStoredApiKey.value).toBe(false);
    });

    /* ---- Unreachable tools ---- */

    it('flags enabled tools that look configured but can never answer', async () => {
        // The condition an administrator cannot see from the list alone: the
        // tool is on, but nothing behind it can respond.
        aiToolService.list.mockResolvedValue({
            data: [
                { id: 1, is_enabled: true, is_standalone: false, connected_source_count: 0 },
                { id: 2, is_enabled: true, is_standalone: false, connected_source_count: 2 },
                { id: 3, is_enabled: true, is_standalone: true, provider_configured: false },
                { id: 4, is_enabled: true, is_standalone: true, provider_configured: true },
                { id: 5, is_enabled: false, is_standalone: false, connected_source_count: 0 },
            ],
            handlers: HANDLERS,
            source_types: [],
            uncovered_sources: [],
            meta: {},
        });

        const t = await mounted();

        expect(t.unreachableTools.value.map((tool) => tool.id)).toEqual([1, 3]);
    });

    it('flags a tool whose handler no longer exists', async () => {
        aiToolService.list.mockResolvedValue({
            data: [
                { id: 1, is_enabled: true, handler_valid: false },
                { id: 2, is_enabled: true, handler_valid: true },
            ],
            handlers: HANDLERS,
            source_types: [],
            uncovered_sources: [],
            meta: {},
        });

        const t = await mounted();

        expect(t.brokenHandlerTools.value.map((tool) => tool.id)).toEqual([1]);
    });

    /* ---- Corrections ---- */

    it('reloads corrections against the newly selected filter', async () => {
        const t = await mounted();
        aiToolService.corrections.mockClear();

        await t.setCorrectionFilter('approved');

        expect(t.correctionFilter.value).toBe('approved');
        expect(aiToolService.corrections).toHaveBeenCalledWith('approved');
    });

    it('opens a correction for review with editable wording', async () => {
        const t = await mounted();

        t.openReview({ id: 8, correction: 'Revenue excludes intercompany.', topic: 'finance' });

        expect(t.activeCorrection.value.id).toBe(8);
        expect(t.reviewForm.correction).toBe('Revenue excludes intercompany.');
        expect(t.reviewForm.topic).toBe('finance');
        expect(t.reviewForm.status).toBe('approved');
        expect(t.reviewForm.review_note).toBe('');
        expect(t.reviewDialogOpen.value).toBe(true);
    });

    it('submits the review for the active correction and closes the dialog', async () => {
        aiToolService.reviewCorrection.mockResolvedValue({});
        const t = await mounted();
        t.openReview({ id: 8, correction: 'Text', topic: null });
        t.reviewForm.status = 'rejected';
        t.reviewForm.review_note = 'Not accurate.';

        await t.review.execute();

        expect(aiToolService.reviewCorrection).toHaveBeenCalledWith(8, expect.objectContaining({
            status: 'rejected',
            review_note: 'Not accurate.',
        }));
        expect(t.reviewDialogOpen.value).toBe(false);
    });

    it('reports the pending correction count for the section badge', async () => {
        aiToolService.corrections.mockResolvedValue({ data: [], pending_count: 4 });

        const t = await mounted();

        expect(t.pendingCorrectionCount.value).toBe(4);
    });
});
