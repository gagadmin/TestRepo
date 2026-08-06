import { computed, reactive, ref } from 'vue';
import { aiToolService } from '@/services/aiToolService';
import { useAsyncAction, useAsyncResource } from './useAsyncResource';

export const AI_TOOL_SECTIONS = [
    { id: 'tools', label: 'Tools', icon: 'pi-wrench' },
    { id: 'coverage', label: 'Coverage', icon: 'pi-sitemap' },
    { id: 'failures', label: 'Failures', icon: 'pi-exclamation-triangle' },
    { id: 'corrections', label: 'Corrections', icon: 'pi-comment' },
];

function emptyProviderOptions() {
    return {
        // Search-API handler (web_search) fields.
        endpoint: '',
        // Held as a comma-separated string in the form; the API normalises it
        // to an array. Joined back on edit.
        allowed_hosts: '',
        auth_scheme: 'bearer',
        key_header: 'X-API-Key',
        max_results: 5,
        timeout_seconds: 15,
        cache_seconds: 300,
        // AI-provider handler (openai_web_search) fields. Unused fields are
        // ignored by the server, which rebuilds options per handler.
        model: '',
        max_output_tokens: 1500,
        tool_type: 'web_search',
    };
}

function emptyForm() {
    return {
        name: '',
        label: '',
        description: '',
        handler: 'generic_http',
        source_types: [],
        is_enabled: true,
        sort_order: 100,
        // Standalone (web_search) provider settings. Ignored for reporting tools.
        options: emptyProviderOptions(),
        // Never populated from the server. Blank on edit means "keep stored key".
        api_key: '',
    };
}

/**
 * State and behaviour for the AI tools admin page.
 */
export function useAiTools() {
    const section = ref('tools');

    const catalogue = useAsyncResource(() => aiToolService.list(), {
        initialValue: {
            data: [],
            handlers: [],
            source_types: [],
            uncovered_sources: [],
            meta: {},
        },
    });

    const failures = useAsyncResource(() => aiToolService.failures(), { initialValue: [] });
    const corrections = useAsyncResource(() => aiToolService.corrections(correctionFilter.value), {
        initialValue: { data: [], pending_count: 0 },
    });

    const correctionFilter = ref('pending');

    async function loadAll() {
        await Promise.all([catalogue.execute(), failures.execute(), corrections.execute()]);
    }

    /* ---- Tool editor ---- */

    const dialogOpen = ref(false);
    const editingId = ref(null);
    // Whether the tool being edited already has a provider key stored, so the
    // UI can say "leave blank to keep" rather than implying none is set.
    const hasStoredApiKey = ref(false);
    const form = reactive(emptyForm());

    function openCreate() {
        Object.assign(form, emptyForm());
        editingId.value = null;
        hasStoredApiKey.value = false;
        save.clearError();
        dialogOpen.value = true;
    }

    function openEdit(tool) {
        const provider = tool.provider ?? {};

        Object.assign(form, {
            name: tool.name,
            label: tool.label,
            description: tool.description,
            handler: tool.handler,
            source_types: [...(tool.source_types ?? [])],
            is_enabled: tool.is_enabled,
            sort_order: tool.sort_order ?? 100,
            options: {
                ...emptyProviderOptions(),
                ...provider,
                allowed_hosts: (provider.allowed_hosts ?? []).join(', '),
            },
            // Never prefilled — the key is write-only from the UI.
            api_key: '',
        });
        hasStoredApiKey.value = tool.has_api_key ?? false;
        editingId.value = tool.id;
        save.clearError();
        dialogOpen.value = true;
    }

    /** Build the request payload, sending provider fields only for standalone tools. */
    function buildPayload() {
        const payload = {
            name: form.name,
            label: form.label,
            description: form.description,
            handler: form.handler,
            is_enabled: form.is_enabled,
            sort_order: form.sort_order,
        };

        if (selectedHandler.value?.standalone) {
            payload.source_types = [];
            payload.options = { ...form.options };
            // Omit a blank key so the server keeps any stored one.
            if (form.api_key) {
                payload.api_key = form.api_key;
            }
        } else {
            payload.source_types = form.source_types;
        }

        return payload;
    }

    const save = useAsyncAction(
        () => (editingId.value
            ? aiToolService.update(editingId.value, buildPayload())
            : aiToolService.create(buildPayload())),
        {
            onSuccess: async () => {
                dialogOpen.value = false;
                await catalogue.execute();
            },
        },
    );

    const toggle = useAsyncAction(
        (tool) => aiToolService.toggle(tool.id, !tool.is_enabled),
        { onSuccess: () => catalogue.execute() },
    );

    const remove = useAsyncAction(
        (tool) => aiToolService.destroy(tool.id),
        { onSuccess: () => catalogue.execute() },
    );

    const resolveFailure = useAsyncAction(
        (failure) => aiToolService.resolveFailure(failure.id),
        { onSuccess: () => failures.execute() },
    );

    /* ---- Correction review ---- */

    const reviewDialogOpen = ref(false);
    const activeCorrection = ref(null);
    const reviewForm = reactive({ status: 'approved', correction: '', topic: '', review_note: '' });

    function openReview(correction) {
        activeCorrection.value = correction;
        Object.assign(reviewForm, {
            status: 'approved',
            // Editable so a reviewer can tighten wording before it starts
            // influencing every future answer.
            correction: correction.correction,
            topic: correction.topic ?? '',
            review_note: '',
        });
        review.clearError();
        reviewDialogOpen.value = true;
    }

    const review = useAsyncAction(
        () => aiToolService.reviewCorrection(activeCorrection.value.id, { ...reviewForm }),
        {
            onSuccess: async () => {
                reviewDialogOpen.value = false;
                await corrections.execute();
            },
        },
    );

    async function setCorrectionFilter(status) {
        correctionFilter.value = status;
        await corrections.execute();
    }

    /* ---- Derived ---- */

    const tools = computed(() => catalogue.data.value?.data ?? []);
    const handlers = computed(() => catalogue.data.value?.handlers ?? []);
    const sourceTypes = computed(() => catalogue.data.value?.source_types ?? []);
    const uncoveredSources = computed(() => catalogue.data.value?.uncovered_sources ?? []);
    const meta = computed(() => catalogue.data.value?.meta ?? {});

    /**
     * Enabled tools that cannot answer: a reporting tool with no connected
     * source, or a standalone tool with no provider configured. Both look
     * configured but will always refuse — the "no ITSM connector" condition.
     */
    const unreachableTools = computed(
        () => tools.value.filter((tool) => {
            if (!tool.is_enabled) {
                return false;
            }

            return tool.is_standalone
                ? tool.provider_configured === false
                : tool.connected_source_count === 0;
        }),
    );

    const brokenHandlerTools = computed(
        () => tools.value.filter((tool) => tool.handler_valid === false),
    );

    const pendingCorrectionCount = computed(
        () => corrections.data.value?.pending_count ?? 0,
    );

    const selectedHandler = computed(
        () => handlers.value.find((handler) => handler.value === form.handler) ?? null,
    );

    const isStandalone = computed(() => selectedHandler.value?.standalone === true);
    // Handlers that reuse an application AI provider's key (e.g. OpenAI) rather
    // than a per-tool endpoint + key.
    const usesAiProvider = computed(() => Boolean(selectedHandler.value?.uses_ai_provider));

    /** Whether the current form is complete enough to submit. */
    const canSave = computed(() => {
        if (!isStandalone.value) {
            return form.source_types.length > 0;
        }

        if (usesAiProvider.value) {
            // Reuses the configured provider key; only the model is required.
            return Boolean(form.options.model);
        }

        return Boolean(form.options.endpoint)
            && Boolean(form.options.allowed_hosts)
            && (hasStoredApiKey.value || Boolean(form.api_key));
    });

    return {
        section,
        sections: AI_TOOL_SECTIONS,

        catalogue,
        failures,
        corrections,
        correctionFilter,
        loadAll,
        setCorrectionFilter,

        tools,
        handlers,
        sourceTypes,
        uncoveredSources,
        meta,
        unreachableTools,
        brokenHandlerTools,
        pendingCorrectionCount,
        selectedHandler,
        isStandalone,
        usesAiProvider,
        canSave,

        dialogOpen,
        editingId,
        hasStoredApiKey,
        form,
        openCreate,
        openEdit,
        save,
        toggle,
        remove,
        resolveFailure,

        reviewDialogOpen,
        activeCorrection,
        reviewForm,
        openReview,
        review,
    };
}

export default useAiTools;
