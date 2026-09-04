import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuditTrail } from './useAuditTrail';
import { auditService } from '@/services/auditService';

vi.mock('@/services/auditService', () => ({
    auditService: {
        list: vi.fn(),
    },
}));

/**
 * Audit trail paging and filtering.
 *
 * The audit trail is governance evidence, so the risk here is not a crash but a
 * quiet omission: paging or filtering that silently skips records would make an
 * incomplete list look complete.
 */
describe('useAuditTrail', () => {
    const page = (overrides = {}) => ({
        data: [{ id: 1, event: 'auth.login' }],
        meta: { current_page: 1, last_page: 3, total: 120 },
        ...overrides,
    });

    beforeEach(() => {
        vi.clearAllMocks();
        auditService.list.mockResolvedValue(page());
    });

    it('loads the first page with no filters', async () => {
        const audit = useAuditTrail();

        await audit.load();

        expect(auditService.list).toHaveBeenCalledWith(1, { event: '', date_from: '', date_to: '' });
        expect(audit.events.value).toHaveLength(1);
        expect(audit.total.value).toBe(120);
    });

    it('exposes paging position from the response, not from local state', async () => {
        // The server is the authority on which page came back; trusting a local
        // counter would drift the moment a request failed or was superseded.
        auditService.list.mockResolvedValue(page({ meta: { current_page: 2, last_page: 3, total: 120 } }));
        const audit = useAuditTrail();

        await audit.load(2);

        expect(audit.currentPage.value).toBe(2);
        expect(audit.hasPrevious.value).toBe(true);
        expect(audit.hasNext.value).toBe(true);
    });

    it('does not page before the first page', async () => {
        const audit = useAuditTrail();
        await audit.load(1);
        auditService.list.mockClear();

        await audit.previous();

        expect(auditService.list).not.toHaveBeenCalled();
    });

    it('does not page past the last page', async () => {
        auditService.list.mockResolvedValue(page({ meta: { current_page: 3, last_page: 3, total: 120 } }));
        const audit = useAuditTrail();
        await audit.load(3);
        auditService.list.mockClear();

        await audit.next();

        expect(auditService.list).not.toHaveBeenCalled();
    });

    it('clamps a request for a page below the first', async () => {
        // The buttons are disabled at the ends, but a stale click can still ask.
        const audit = useAuditTrail();

        await audit.load(0);

        expect(auditService.list).toHaveBeenCalledWith(1, expect.anything());
    });

    it('moves forward and back through pages', async () => {
        auditService.list.mockResolvedValue(page({ meta: { current_page: 2, last_page: 3, total: 120 } }));
        const audit = useAuditTrail();
        await audit.load(2);
        auditService.list.mockClear();

        await audit.next();
        expect(auditService.list).toHaveBeenCalledWith(3, expect.anything());

        auditService.list.mockClear();
        await audit.previous();
        expect(auditService.list).toHaveBeenCalledWith(1, expect.anything());
    });

    it('sends only the filters that were set', async () => {
        const audit = useAuditTrail();
        audit.filters.event = 'auth.login';

        await audit.applyFilters();

        // Blank values are dropped by the service layer, so the composable can
        // pass the whole form without special-casing.
        expect(auditService.list).toHaveBeenCalledWith(1, {
            event: 'auth.login',
            date_from: '',
            date_to: '',
        });
    });

    it('returns to the first page when a filter is applied', async () => {
        /*
         * Staying on page five of an unfiltered result while showing filtered
         * data is how a reviewer concludes there is no evidence when there is.
         */
        auditService.list.mockResolvedValue(page({ meta: { current_page: 5, last_page: 9, total: 400 } }));
        const audit = useAuditTrail();
        await audit.load(5);

        auditService.list.mockClear();
        auditService.list.mockResolvedValue(page());
        audit.filters.event = 'user.access.updated';
        await audit.applyFilters();

        expect(auditService.list).toHaveBeenCalledWith(1, expect.objectContaining({
            event: 'user.access.updated',
        }));
    });

    it('reports whether any filter is active', async () => {
        const audit = useAuditTrail();
        expect(audit.isFiltered.value).toBe(false);

        audit.filters.date_from = '2026-09-01';
        expect(audit.isFiltered.value).toBe(true);
    });

    it('clearing filters resets the form and reloads from the first page', async () => {
        const audit = useAuditTrail();
        audit.filters.event = 'auth.login';
        audit.filters.date_from = '2026-09-01';
        await audit.applyFilters();
        auditService.list.mockClear();

        await audit.clearFilters();

        expect(audit.isFiltered.value).toBe(false);
        expect(auditService.list).toHaveBeenCalledWith(1, { event: '', date_from: '', date_to: '' });
    });

    it('surfaces a failure without discarding the rows already shown', async () => {
        const audit = useAuditTrail();
        await audit.load();

        auditService.list.mockRejectedValue(Object.assign(new Error('nope'), {
            message: 'Your account does not have permission to view the audit trail.',
        }));
        await audit.load(2);

        expect(audit.error.value).toContain('permission');
        expect(audit.events.value).toHaveLength(1);
    });

    it('falls back to safe paging values before anything has loaded', async () => {
        const audit = useAuditTrail();

        expect(audit.events.value).toEqual([]);
        expect(audit.total.value).toBe(0);
        expect(audit.hasPrevious.value).toBe(false);
        expect(audit.hasNext.value).toBe(false);
    });
});
