import { computed, ref } from 'vue';
import { securityService } from '@/services/securityService';
import { useAsyncResource, useAsyncAction } from './useAsyncResource';
import { areaOptions, percentageBarOptions, pieOptions, pieSeries, SEVERITY_PALETTE } from './useCharts';

export const SECURITY_SECTIONS = [
    { id: 'overview', label: 'Overview', icon: 'pi-gauge' },
    { id: 'threats', label: 'Threats', icon: 'pi-exclamation-triangle' },
    { id: 'identity', label: 'Identity & access', icon: 'pi-id-card' },
    { id: 'incidents', label: 'Incidents', icon: 'pi-flag' },
    { id: 'compliance', label: 'Compliance', icon: 'pi-verified' },
    { id: 'assets', label: 'Assets', icon: 'pi-server' },
    { id: 'coverage', label: 'Coverage gaps', icon: 'pi-link' },
];

export const TREND_OPTIONS = [
    { label: 'Last 7 days', value: 7 },
    { label: 'Last 30 days', value: 30 },
    { label: 'Last 90 days', value: 90 },
];

/**
 * All Security page behaviour: fetching, section state, event triage, charts.
 *
 * The page component consumes this and renders. It contains no API calls and
 * no derived-metric logic, which is what makes the page testable in isolation
 * and this logic testable without mounting anything.
 */
export function useSecurityDashboard() {
    const trendDays = ref(30);
    const section = ref('overview');

    const posture = useAsyncResource(
        () => securityService.dashboard(trendDays.value),
        { keepPreviousOnError: true },
    );

    const data = posture.data;

    async function load() {
        return posture.execute();
    }

    async function changeTrend(days) {
        trendDays.value = days;

        return load();
    }

    const scanAction = useAsyncAction(() => securityService.scan(), { onSuccess: load });

    /* ---- Event triage ---- */

    const dialogOpen = ref(false);
    const activeEvent = ref(null);
    const form = ref({ status: 'acknowledged', resolution_note: '' });

    function openEvent(event) {
        activeEvent.value = event;
        form.value = {
            // An open finding is being looked at right now, so acknowledge is
            // the sensible default; anything else keeps its current status.
            status: event.status === 'open' ? 'acknowledged' : event.status,
            resolution_note: '',
        };
        dialogOpen.value = true;
    }

    const saveAction = useAsyncAction(
        () => securityService.updateEvent(activeEvent.value.id, form.value),
        {
            onSuccess: async () => {
                dialogOpen.value = false;
                await load();
            },
        },
    );

    const requiresNote = computed(
        () => ['resolved', 'false_positive'].includes(form.value.status),
    );

    /* ---- Derived view data ---- */

    const trendSeries = computed(() => [
        {
            name: 'Security findings',
            data: data.value?.threats?.trend?.map((point) => point.events) ?? [],
        },
        {
            name: 'Failed logins',
            data: data.value?.threats?.trend?.map((point) => point.failed_logins) ?? [],
        },
    ]);

    const trendChartOptions = computed(() => areaOptions(
        data.value?.threats?.trend?.map((point) => point.date) ?? [],
        { emptyText: 'No security telemetry for this period.' },
    ));

    const severityChartOptions = computed(() => pieOptions(
        data.value?.threats?.severity_breakdown ?? [],
        { palette: SEVERITY_PALETTE, emptyText: 'No open findings.' },
    ));

    const severityChartSeries = computed(
        () => pieSeries(data.value?.threats?.severity_breakdown ?? []),
    );

    const authChartOptions = computed(() => ({
        ...pieOptions([], { palette: ['#19a7a0', '#d05c5c'] }),
        labels: ['Successful', 'Failed'],
        noData: { text: 'No authentication activity.' },
    }));

    const authChartSeries = computed(() => [
        data.value?.identity?.authentication?.successful ?? 0,
        data.value?.identity?.authentication?.failed ?? 0,
    ]);

    const complianceChartOptions = computed(() => percentageBarOptions(
        data.value?.compliance?.by_framework?.map((row) => row.framework) ?? [],
    ));

    const complianceChartSeries = computed(() => [{
        name: 'Controls passed',
        data: data.value?.compliance?.by_framework?.map((row) => row.percentage) ?? [],
    }]);

    /** The four sections that need an external connector. */
    const coverageSections = computed(() => [
        data.value?.vulnerability_management,
        data.value?.endpoint_security,
        data.value?.email_security,
        data.value?.cloud_security,
    ].filter(Boolean));

    const failingControlCount = computed(() => {
        const compliance = data.value?.compliance;

        if (!compliance) return 0;

        return compliance.controls_total - compliance.controls_passed;
    });

    return {
        // state
        data,
        loading: posture.loading,
        isInitialLoading: posture.isInitialLoading,
        error: posture.error,
        clearError: posture.clearError,
        trendDays,
        section,
        sections: SECURITY_SECTIONS,
        trendOptions: TREND_OPTIONS,

        // actions
        load,
        changeTrend,
        scan: scanAction.execute,
        scanning: scanAction.saving,
        scanError: scanAction.error,

        // triage
        dialogOpen,
        activeEvent,
        form,
        requiresNote,
        openEvent,
        saveEvent: saveAction.execute,
        saving: saveAction.saving,
        saveError: saveAction.error,

        // charts
        trendChartOptions,
        trendSeries,
        severityChartOptions,
        severityChartSeries,
        authChartOptions,
        authChartSeries,
        complianceChartOptions,
        complianceChartSeries,

        // derived
        coverageSections,
        failingControlCount,
    };
}

export default useSecurityDashboard;
