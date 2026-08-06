import http, { normalizeError, queryParams } from './http';

/**
 * Report definitions, generation, and export.
 */
export const reportService = {
    async list() {
        try {
            const { data } = await http.get('/api/reports');

            return data;
        } catch (error) {
            throw normalizeError(error, 'Reports could not be loaded.', 'reports');
        }
    },

    async show(reportId, filters = {}) {
        try {
            const { data } = await http.get(`/api/reports/${reportId}`, {
                params: queryParams(filters),
            });

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'The report could not be loaded.');
        }
    },

    async create(payload) {
        try {
            const { data } = await http.post('/api/reports', payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The report definition could not be saved.');
        }
    },

    async update(reportId, payload) {
        try {
            const { data } = await http.put(`/api/reports/${reportId}`, payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The report definition could not be saved.');
        }
    },

    async generate(reportId, filters = {}) {
        try {
            const { data } = await http.post(`/api/reports/${reportId}/generate`, queryParams(filters));

            return data;
        } catch (error) {
            throw normalizeError(error, 'The report could not be generated.');
        }
    },

    /**
     * Exports are a file download, so the caller receives the absolute URL
     * rather than a parsed body.
     */
    exportUrl(reportId, format, filters = {}) {
        const params = new URLSearchParams(queryParams(filters));
        const query = params.toString();

        return `/api/reports/${reportId}/export/${format}${query ? `?${query}` : ''}`;
    },
};

export default reportService;
