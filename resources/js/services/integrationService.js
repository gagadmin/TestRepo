import http, { normalizeError, queryParams } from './http';

/**
 * Data source / integration administration.
 */
export const integrationService = {
    async list() {
        try {
            const { data } = await http.get('/api/integrations');

            return data;
        } catch (error) {
            throw normalizeError(
                error,
                'Integration records could not be loaded.',
                'manage integrations',
            );
        }
    },

    async create(payload) {
        try {
            const { data } = await http.post('/api/integrations', payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The data source could not be saved.');
        }
    },

    async update(sourceId, payload) {
        try {
            const { data } = await http.put(`/api/integrations/${sourceId}`, payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The data source could not be saved.');
        }
    },

    async destroy(sourceId) {
        await http.delete(`/api/integrations/${sourceId}`);
    },

    async test(sourceId) {
        try {
            const { data } = await http.post(`/api/integrations/${sourceId}/test`);

            return data;
        } catch (error) {
            // This endpoint nests its reason under result.message.
            const normalized = normalizeError(error, 'The connection test did not succeed.');
            normalized.message = error?.response?.data?.result?.message ?? normalized.message;

            throw normalized;
        }
    },

    async preview(sourceId, filters = {}) {
        try {
            const { data } = await http.get(`/api/integrations/${sourceId}/preview`, {
                params: queryParams(filters),
            });

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'Search Console data could not be loaded.');
        }
    },

    async testSearchConsole(payload) {
        try {
            const { data } = await http.post('/api/integrations/search-console/test', payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The Search Console test did not succeed.');
        }
    },
};

export default integrationService;
