import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { useSeoInsights } from './useSeoInsights';
import { seoService } from '@/services/seoService';

vi.mock('@/services/seoService', () => ({
    seoService: {
        listSources: vi.fn(),
        insights: vi.fn(),
        actionPlans: vi.fn(),
        research: vi.fn(),
        saveProfile: vi.fn(),
        generateActionPlan: vi.fn(),
        generateResearch: vi.fn(),
    },
}));

/**
 * SEO insights page behaviour.
 *
 * Two things carry real risk here: the profile round-trip, which turns stored
 * arrays into comma-separated form text and back into structured regions, and
 * the load sequence, which fans one property selection out into three requests.
 */
describe('useSeoInsights', () => {
    const SOURCES = [
        { id: 4, name: 'example.test' },
        { id: 9, name: 'other.test' },
    ];

    const INSIGHTS = {
        available: true,
        summary: { clicks: 100 },
        health: { score: 72, breakdown: { ctr: 20 } },
        window: { start: '2026-08-01', end: '2026-08-31' },
        top_opportunities: [{ query: 'widgets' }],
        opportunities: {
            positions_6_20: [{ query: 'a' }],
            ctr_gaps: [{ query: 'b' }],
            countries: [{ country: 'AE' }],
        },
        trends: { available: true, declining: [], gaining: [], monitoring: [] },
        sections: { ctr: 'ok' },
        profile: {
            categories: ['Vehicles', 'Parts'],
            regions: [{ name: 'United Arab Emirates', code: 'AE' }, { name: 'Global' }],
            brand_terms: ['acme'],
            competitor_seeds: ['rival.test'],
        },
    };

    beforeEach(() => {
        vi.clearAllMocks();
        seoService.listSources.mockResolvedValue(SOURCES);
        seoService.insights.mockResolvedValue(INSIGHTS);
        seoService.actionPlans.mockResolvedValue([{ id: 1, created_at: 'now' }]);
        seoService.research.mockResolvedValue({ findings: { summary: 'ok' } });
        seoService.saveProfile.mockResolvedValue({});
        seoService.generateActionPlan.mockResolvedValue({});
        seoService.generateResearch.mockResolvedValue({});
    });

    /* ---- Load sequence ---- */

    it('selects the first property and loads it', async () => {
        const seo = useSeoInsights();

        await seo.init();
        await nextTick();

        expect(seo.selectedSourceId.value).toBe(4);
        expect(seo.available.value).toBe(true);
        expect(seo.summary.value).toEqual({ clicks: 100 });
    });

    it('requests each resource exactly once per load', async () => {
        /*
         * Regression: selecting the first property inside init() also tripped a
         * watcher on the same ref, so init() loaded everything twice — three
         * duplicate requests on every page open, against an endpoint throttled
         * to thirty a minute.
         */
        const seo = useSeoInsights();

        await seo.init();
        await nextTick();

        expect(seoService.insights).toHaveBeenCalledTimes(1);
        expect(seoService.actionPlans).toHaveBeenCalledTimes(1);
        expect(seoService.research).toHaveBeenCalledTimes(1);
    });

    it('does nothing when the account has no connected properties', async () => {
        seoService.listSources.mockResolvedValue([]);
        const seo = useSeoInsights();

        await seo.init();
        await nextTick();

        expect(seo.selectedSourceId.value).toBeNull();
        expect(seoService.insights).not.toHaveBeenCalled();
        expect(seo.available.value).toBe(false);
    });

    it('loads the newly chosen property once', async () => {
        const seo = useSeoInsights();
        await seo.init();
        await nextTick();
        vi.clearAllMocks();
        seoService.insights.mockResolvedValue(INSIGHTS);
        seoService.actionPlans.mockResolvedValue([]);
        seoService.research.mockResolvedValue(null);

        await seo.onSelectSource(9);
        await nextTick();

        expect(seo.selectedSourceId.value).toBe(9);
        expect(seoService.insights).toHaveBeenCalledTimes(1);
        expect(seoService.insights).toHaveBeenCalledWith(9);
    });

    /* ---- Profile round-trip ---- */

    it('renders the stored profile as editable text', async () => {
        const seo = useSeoInsights();
        await seo.init();
        await nextTick();

        expect(seo.profileForm.categories).toBe('Vehicles, Parts');
        // A region keeps its code where it has one, and is bare where it does not.
        expect(seo.profileForm.regions).toBe('United Arab Emirates:AE, Global');
        expect(seo.profileForm.brand_terms).toBe('acme');
    });

    it('parses the form text back into structured values on save', async () => {
        const seo = useSeoInsights();
        await seo.init();
        await nextTick();

        seo.profileForm.categories = ' Vehicles , Parts ,, ';
        seo.profileForm.regions = 'Saudi Arabia:sa, Global';
        seo.profileForm.brand_terms = 'acme';
        seo.profileForm.competitor_seeds = '';

        await seo.saveProfile.execute();

        expect(seoService.saveProfile).toHaveBeenCalledWith(4, {
            // Whitespace trimmed and blank entries dropped.
            categories: ['Vehicles', 'Parts'],
            // Country codes are upper-cased for the API.
            regions: [{ name: 'Saudi Arabia', code: 'SA' }, { name: 'Global' }],
            brand_terms: ['acme'],
            competitor_seeds: [],
        });
    });

    it('shows an empty profile form when the property has none', async () => {
        seoService.insights.mockResolvedValue({ ...INSIGHTS, profile: null });
        const seo = useSeoInsights();

        await seo.init();
        await nextTick();

        expect(seo.profileForm.categories).toBe('');
        expect(seo.profileForm.regions).toBe('');
    });

    /* ---- Generated artefacts ---- */

    it('reloads the plan list after generating one', async () => {
        const seo = useSeoInsights();
        await seo.init();
        await nextTick();
        seoService.actionPlans.mockClear();

        await seo.generatePlan.execute();

        expect(seoService.generateActionPlan).toHaveBeenCalledWith(4);
        expect(seoService.actionPlans).toHaveBeenCalledTimes(1);
    });

    it('exposes the newest plan first', async () => {
        seoService.actionPlans.mockResolvedValue([{ id: 7 }, { id: 3 }]);
        const seo = useSeoInsights();

        await seo.init();
        await nextTick();

        expect(seo.latestPlan.value.id).toBe(7);
    });

    it('reloads research after generating it', async () => {
        const seo = useSeoInsights();
        await seo.init();
        await nextTick();
        seoService.research.mockClear();

        await seo.generateResearch.execute();

        expect(seoService.generateResearch).toHaveBeenCalledWith(4);
        expect(seoService.research).toHaveBeenCalledTimes(1);
    });

    /* ---- Empty and unavailable states ---- */

    it('falls back to safe shapes when the payload is unavailable', async () => {
        // The page renders every section unconditionally, so a missing key must
        // not surface as undefined.
        seoService.insights.mockResolvedValue({ available: false });
        seoService.actionPlans.mockResolvedValue([]);
        seoService.research.mockResolvedValue(null);
        const seo = useSeoInsights();

        await seo.init();
        await nextTick();

        expect(seo.available.value).toBe(false);
        expect(seo.summary.value).toEqual({});
        expect(seo.health.value).toEqual({ score: 0, breakdown: {} });
        expect(seo.topOpportunities.value).toEqual([]);
        expect(seo.positions.value).toEqual([]);
        expect(seo.ctrGaps.value).toEqual([]);
        expect(seo.countries.value).toEqual([]);
        expect(seo.trends.value.available).toBe(false);
        expect(seo.latestPlan.value).toBeNull();
        expect(seo.researchFindings.value).toBeNull();
    });
});
