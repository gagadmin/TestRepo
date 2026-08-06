import http, { normalizeError } from './http';

/**
 * Administration of what the AI assistant can reach.
 */
export const aiToolService = {
    async list() {
        try {
            const { data } = await http.get('/api/admin/ai-tools');

            return data;
        } catch (error) {
            throw normalizeError(error, 'AI tools could not be loaded.', 'AI tools');
        }
    },

    async create(payload) {
        try {
            const { data } = await http.post('/api/admin/ai-tools', payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The tool could not be created.');
        }
    },

    async update(toolId, payload) {
        try {
            const { data } = await http.put(`/api/admin/ai-tools/${toolId}`, payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The tool could not be saved.');
        }
    },

    async toggle(toolId, isEnabled) {
        try {
            const { data } = await http.patch(`/api/admin/ai-tools/${toolId}/toggle`, {
                is_enabled: isEnabled,
            });

            return data;
        } catch (error) {
            throw normalizeError(error, 'The tool could not be updated.');
        }
    },

    async destroy(toolId) {
        try {
            const { data } = await http.delete(`/api/admin/ai-tools/${toolId}`);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The tool could not be removed.');
        }
    },

    /* ---- Connector failures ---- */

    async failures() {
        try {
            const { data } = await http.get('/api/admin/ai-tools/failures');

            return data.data;
        } catch (error) {
            throw normalizeError(error, 'Connector failures could not be loaded.');
        }
    },

    async resolveFailure(failureId) {
        try {
            const { data } = await http.post(`/api/admin/ai-tools/failures/${failureId}/resolve`);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The failure could not be marked resolved.');
        }
    },

    /* ---- Correction review ---- */

    async corrections(status = 'pending') {
        try {
            const { data } = await http.get('/api/admin/ai-tools/corrections', {
                params: { status },
            });

            return data;
        } catch (error) {
            throw normalizeError(error, 'Corrections could not be loaded.');
        }
    },

    async reviewCorrection(correctionId, payload) {
        try {
            const { data } = await http.post(
                `/api/admin/ai-tools/corrections/${correctionId}/review`,
                payload,
            );

            return data;
        } catch (error) {
            throw normalizeError(error, 'The review could not be saved.');
        }
    },
};

export default aiToolService;
