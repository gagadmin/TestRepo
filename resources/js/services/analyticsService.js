import http, { normalizeError } from './http';

/**
 * Advanced (deterministic) analytics insights.
 */
export const analyticsService = {
    async list() {
        try {
            const { data } = await http.get('/api/analytics');

            return data;
        } catch (error) {
            throw normalizeError(
                error,
                'Advanced analytics could not be loaded.',
                'advanced analytics',
            );
        }
    },

    async generate(reportId) {
        try {
            const { data } = await http.post(`/api/analytics/reports/${reportId}`);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The analytics run could not be started.');
        }
    },
};

export default analyticsService;
