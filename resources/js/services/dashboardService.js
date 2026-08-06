import http, { normalizeError, queryParams } from './http';

/**
 * Dashboards and the two live analytics panels they host.
 */
export const dashboardService = {
    async list() {
        try {
            const { data } = await http.get('/api/dashboards');

            return data;
        } catch (error) {
            throw normalizeError(error, 'Dashboards could not be loaded.', 'dashboards');
        }
    },

    async show(slug) {
        try {
            const { data } = await http.get(`/api/dashboards/${slug}`);

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'The dashboard could not be loaded.');
        }
    },

    async searchConsole(filters = {}) {
        try {
            const { data } = await http.get('/api/dashboards/search-console', {
                params: queryParams(filters),
            });

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'Search Console analytics could not be loaded.');
        }
    },

    async freshservice(dataSourceId, filters = {}) {
        try {
            const { data } = await http.get('/api/dashboards/freshservice', {
                params: queryParams({ data_source_id: dataSourceId, ...filters }),
            });

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'Freshservice ticket analytics could not be loaded.');
        }
    },
};

export default dashboardService;
