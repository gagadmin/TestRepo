import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useSecurityDashboard } from './useSecurityDashboard';
import { securityService } from '@/services/securityService';

vi.mock('@/services/securityService', () => ({
    securityService: {
        dashboard: vi.fn(),
        scan: vi.fn(),
        updateEvent: vi.fn(),
    },
}));

/**
 * Security page behaviour, tested without mounting the page.
 *
 * This composable holds everything the Security page does beyond rendering:
 * the trend window, event triage defaults, and the mapping from payload to
 * chart series. It was extracted from the legacy workspace during the security
 * migration and never gained tests.
 */
describe('useSecurityDashboard', () => {
    const PAYLOAD = {
        threats: {
            trend: [
                { date: '2026-09-01', events: 3, failed_logins: 11 },
                { date: '2026-09-02', events: 1, failed_logins: 4 },
            ],
            severity_breakdown: [
                { label: 'Critical', value: 2 },
                { label: 'High', value: 5 },
            ],
        },
        identity: { authentication: { successful: 120, failed: 9 } },
        compliance: {
            controls_total: 10,
            controls_passed: 7,
            by_framework: [{ framework: 'ISO 27001', percentage: 80 }],
        },
        vulnerability_management: { connected: false },
        endpoint_security: { connected: false },
    };

    beforeEach(() => {
        vi.clearAllMocks();
        securityService.dashboard.mockResolvedValue(PAYLOAD);
        securityService.scan.mockResolvedValue({ message: 'Scan queued.' });
        securityService.updateEvent.mockResolvedValue({});
    });

    async function loaded() {
        const dashboard = useSecurityDashboard();
        await dashboard.load();

        return dashboard;
    }

    /* ---- Trend window ---- */

    it('requests thirty days by default', async () => {
        await loaded();

        expect(securityService.dashboard).toHaveBeenCalledWith(30);
    });

    it('refetches against the newly chosen window', async () => {
        const d = await loaded();
        securityService.dashboard.mockClear();

        await d.changeTrend(7);

        expect(d.trendDays.value).toBe(7);
        expect(securityService.dashboard).toHaveBeenCalledWith(7);
    });

    /* ---- Event triage ---- */

    it('defaults an open finding to acknowledged', async () => {
        // Opening the dialog means somebody is looking at it now, so
        // acknowledging is the honest default.
        const d = await loaded();

        d.openEvent({ id: 1, status: 'open' });

        expect(d.form.value.status).toBe('acknowledged');
        expect(d.form.value.resolution_note).toBe('');
        expect(d.dialogOpen.value).toBe(true);
    });

    it('keeps the current status for a finding already past open', async () => {
        const d = await loaded();

        d.openEvent({ id: 2, status: 'resolved' });

        expect(d.form.value.status).toBe('resolved');
    });

    it.each([
        ['resolved', true],
        ['false_positive', true],
        ['acknowledged', false],
        ['open', false],
    ])('requires a note for %s: %s', async (status, expected) => {
        // Closing a finding has to leave a reason behind; acknowledging does not.
        const d = await loaded();
        d.openEvent({ id: 3, status: 'open' });
        d.form.value.status = status;

        expect(d.requiresNote.value).toBe(expected);
    });

    it('submits triage for the active finding and reloads', async () => {
        const d = await loaded();
        d.openEvent({ id: 42, status: 'open' });
        d.form.value.resolution_note = 'Known scanner.';
        securityService.dashboard.mockClear();

        await d.saveEvent();

        expect(securityService.updateEvent).toHaveBeenCalledWith(42, {
            status: 'acknowledged',
            resolution_note: 'Known scanner.',
        });
        expect(d.dialogOpen.value).toBe(false);
        expect(securityService.dashboard).toHaveBeenCalled();
    });

    it('reloads the dashboard after a scan', async () => {
        const d = await loaded();
        securityService.dashboard.mockClear();

        await d.scan();

        expect(securityService.scan).toHaveBeenCalled();
        expect(securityService.dashboard).toHaveBeenCalled();
    });

    /* ---- Derived view data ---- */

    it('maps the threat trend into two named series', async () => {
        const d = await loaded();

        expect(d.trendSeries.value).toEqual([
            { name: 'Security findings', data: [3, 1] },
            { name: 'Failed logins', data: [11, 4] },
        ]);
    });

    it('maps authentication counts in successful-then-failed order', async () => {
        const d = await loaded();

        expect(d.authChartSeries.value).toEqual([120, 9]);
    });

    it('maps compliance percentages per framework', async () => {
        const d = await loaded();

        expect(d.complianceChartSeries.value).toEqual([{ name: 'Controls passed', data: [80] }]);
    });

    it('counts the failing controls', async () => {
        const d = await loaded();

        expect(d.failingControlCount.value).toBe(3);
    });

    it('reports no failing controls before anything has loaded', async () => {
        const d = useSecurityDashboard();

        expect(d.failingControlCount.value).toBe(0);
    });

    it('lists only the connector sections the payload actually carries', async () => {
        // The payload above omits email and cloud; the page must not render
        // holes for them.
        const d = await loaded();

        expect(d.coverageSections.value).toHaveLength(2);
    });

    /* ---- Empty state ---- */

    it('yields empty series rather than throwing on an empty payload', async () => {
        securityService.dashboard.mockResolvedValue({});
        const d = await loaded();

        expect(d.trendSeries.value[0].data).toEqual([]);
        expect(d.severityChartSeries.value).toEqual([]);
        expect(d.coverageSections.value).toEqual([]);
        expect(d.authChartSeries.value).toEqual([0, 0]);
    });
});
