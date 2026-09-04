import http, { normalizeError, queryParams } from './http';

/**
 * Audit trail evidence.
 *
 * Read-only by design: audit records are governance evidence and the API
 * exposes no way to alter them.
 */
export const auditService = {
    async list(page = 1, filters = {}) {
        try {
            const { data } = await http.get('/api/admin/audit', {
                params: { page, ...queryParams(filters) },
            });

            return data;
        } catch (error) {
            const normalized = normalizeError(error, 'Audit events could not be loaded.', 'audit events');

            if (normalized.isForbidden) {
                normalized.message = 'Your account does not have permission to view the audit trail.';
            }

            throw normalized;
        }
    },
};

export default auditService;
