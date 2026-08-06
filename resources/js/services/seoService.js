import http, { normalizeError } from './http';

/**
 * SEO insights: deterministic Search Console analysis and per-property profiles.
 */
export const seoService = {
    async listSources() {
        try {
            const { data } = await http.get('/api/seo');

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'SEO properties could not be loaded.', 'SEO');
        }
    },

    async insights(sourceId, params = {}) {
        try {
            const { data } = await http.get(`/api/seo/${sourceId}`, { params });

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'SEO insights could not be loaded.', 'SEO');
        }
    },

    async saveProfile(sourceId, payload) {
        try {
            const { data } = await http.put(`/api/seo/${sourceId}/profile`, payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The SEO profile could not be saved.');
        }
    },

    async actionPlans(sourceId) {
        try {
            const { data } = await http.get(`/api/seo/${sourceId}/action-plans`);

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'Action plans could not be loaded.', 'SEO');
        }
    },

    async generateActionPlan(sourceId) {
        try {
            const { data } = await http.post(`/api/seo/${sourceId}/action-plan`);

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'The action plan could not be generated.');
        }
    },

    async research(sourceId) {
        try {
            const { data } = await http.get(`/api/seo/${sourceId}/research`);

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'Research could not be loaded.', 'SEO');
        }
    },

    async generateResearch(sourceId) {
        try {
            const { data } = await http.post(`/api/seo/${sourceId}/research`);

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'Web research could not be run.');
        }
    },
};

export default seoService;
