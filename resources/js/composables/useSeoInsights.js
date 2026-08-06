import { computed, reactive, ref, watch } from 'vue';
import { seoService } from '@/services/seoService';
import { useAsyncAction, useAsyncResource } from './useAsyncResource';

export const SEO_SECTIONS = [
    { id: 'opportunities', label: 'Top 5 opportunities', icon: 'pi-bullseye' },
    { id: 'ctr', label: 'CTR gaps', icon: 'pi-percentage' },
    { id: 'countries', label: 'Markets', icon: 'pi-globe' },
    { id: 'trends', label: 'Trends', icon: 'pi-chart-line' },
    { id: 'health', label: 'Health', icon: 'pi-heart' },
    { id: 'research', label: 'Web research', icon: 'pi-globe' },
    { id: 'plan', label: 'AI action plan', icon: 'pi-sparkles' },
    { id: 'profile', label: 'Categories & region', icon: 'pi-tags' },
];

function emptyProfileForm() {
    return {
        categories: '',
        // "Name:CODE" pairs, comma-separated, e.g. "United Arab Emirates:AE".
        regions: '',
        brand_terms: '',
        competitor_seeds: '',
    };
}

/** Turn the stored profile arrays into the comma-separated form strings. */
function profileToForm(profile) {
    if (!profile) {
        return emptyProfileForm();
    }

    return {
        categories: (profile.categories ?? []).join(', '),
        regions: (profile.regions ?? [])
            .map((r) => (r.code ? `${r.name}:${r.code}` : r.name))
            .join(', '),
        brand_terms: (profile.brand_terms ?? []).join(', '),
        competitor_seeds: (profile.competitor_seeds ?? []).join(', '),
    };
}

function splitList(value) {
    return String(value || '')
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);
}

/** Parse "Name:CODE" pairs into [{name, code}]. */
function parseRegions(value) {
    return splitList(value).map((entry) => {
        const [name, code] = entry.split(':').map((part) => (part || '').trim());
        return code ? { name, code: code.toUpperCase() } : { name };
    });
}

export function useSeoInsights() {
    const section = ref('opportunities');
    const selectedSourceId = ref(null);

    const sources = useAsyncResource(() => seoService.listSources(), { initialValue: [] });

    const insights = useAsyncResource(
        () => (selectedSourceId.value
            ? seoService.insights(selectedSourceId.value)
            : Promise.resolve(null)),
        { initialValue: null },
    );

    const plans = useAsyncResource(
        () => (selectedSourceId.value
            ? seoService.actionPlans(selectedSourceId.value)
            : Promise.resolve([])),
        { initialValue: [] },
    );

    const research = useAsyncResource(
        () => (selectedSourceId.value
            ? seoService.research(selectedSourceId.value)
            : Promise.resolve(null)),
        { initialValue: null },
    );

    const profileForm = reactive(emptyProfileForm());

    async function loadSources() {
        const list = await sources.execute();

        if (!selectedSourceId.value && Array.isArray(list) && list.length) {
            selectedSourceId.value = list[0].id;
        }
    }

    async function loadInsights() {
        const data = await insights.execute();
        Object.assign(profileForm, profileToForm(data?.profile));
        await Promise.all([plans.execute(), research.execute()]);
    }

    // Reload insights whenever the selected property changes.
    watch(selectedSourceId, (id) => {
        if (id) {
            loadInsights();
        }
    });

    async function init() {
        await loadSources();
        if (selectedSourceId.value) {
            await loadInsights();
        }
    }

    function onSelectSource(id) {
        selectedSourceId.value = id;
    }

    const saveProfile = useAsyncAction(
        () => seoService.saveProfile(selectedSourceId.value, {
            categories: splitList(profileForm.categories),
            regions: parseRegions(profileForm.regions),
            brand_terms: splitList(profileForm.brand_terms),
            competitor_seeds: splitList(profileForm.competitor_seeds),
        }),
        { onSuccess: () => loadInsights() },
    );

    const generatePlan = useAsyncAction(
        () => seoService.generateActionPlan(selectedSourceId.value),
        { onSuccess: () => plans.execute() },
    );

    const generateResearch = useAsyncAction(
        () => seoService.generateResearch(selectedSourceId.value),
        { onSuccess: () => research.execute() },
    );

    /* ---- derived ---- */

    const data = computed(() => insights.data.value ?? null);
    const available = computed(() => Boolean(data.value?.available));
    const summary = computed(() => data.value?.summary ?? {});
    const health = computed(() => data.value?.health ?? { score: 0, breakdown: {} });
    const window = computed(() => data.value?.window ?? null);
    const topOpportunities = computed(() => data.value?.top_opportunities ?? []);
    const positions = computed(() => data.value?.opportunities?.positions_6_20 ?? []);
    const ctrGaps = computed(() => data.value?.opportunities?.ctr_gaps ?? []);
    const countries = computed(() => data.value?.opportunities?.countries ?? []);
    const trends = computed(() => data.value?.trends ?? {
        available: false,
        reason: '',
        declining: [],
        gaining: [],
        monitoring: [],
    });
    const sections = computed(() => data.value?.sections ?? {});
    const latestPlan = computed(() => plans.data.value?.[0] ?? null);
    const researchFindings = computed(() => research.data.value?.findings ?? null);

    return {
        section,
        sectionTabs: SEO_SECTIONS,
        selectedSourceId,
        sources,
        insights,
        init,
        loadInsights,
        onSelectSource,

        profileForm,
        saveProfile,
        plans,
        generatePlan,
        latestPlan,
        research,
        generateResearch,
        researchFindings,

        data,
        available,
        summary,
        health,
        window,
        topOpportunities,
        positions,
        ctrGaps,
        countries,
        trends,
        sectionStatuses: sections,
    };
}

export default useSeoInsights;
