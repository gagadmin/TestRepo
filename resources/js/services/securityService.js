import http, { normalizeError, queryParams } from './http';

/**
 * Security posture, findings, and on-demand scans.
 */
export const securityService = {
    async dashboard(trendDays = 30) {
        try {
            const { data } = await http.get('/api/security', {
                params: { trend_days: trendDays },
            });

            return data.data;
        } catch (error) {
            const normalized = normalizeError(error, 'The security posture could not be loaded.');

            if (normalized.isForbidden) {
                normalized.message = 'Security data is restricted to the IT department and security roles.';
            }

            throw normalized;
        }
    },

    async events(filters = {}, page = 1) {
        try {
            const { data } = await http.get('/api/security/events', {
                params: { page, ...queryParams(filters) },
            });

            return data;
        } catch (error) {
            throw normalizeError(error, 'Security findings could not be loaded.', 'security findings');
        }
    },

    async updateEvent(eventId, payload) {
        try {
            const { data } = await http.put(`/api/security/events/${eventId}`, payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The security event could not be updated.');
        }
    },

    async scan() {
        try {
            const { data } = await http.post('/api/security/scan');

            return data;
        } catch (error) {
            throw normalizeError(error, 'The security scan could not be started.');
        }
    },
};

export default securityService;
