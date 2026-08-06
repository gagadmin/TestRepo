import http, { normalizeError } from './http';

/**
 * Scheduled report delivery.
 */
export const scheduleService = {
    async list() {
        try {
            const { data } = await http.get('/api/schedules');

            return data;
        } catch (error) {
            throw normalizeError(error, 'Schedules could not be loaded.', 'schedules');
        }
    },

    async create(payload) {
        try {
            const { data } = await http.post('/api/schedules', payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The schedule could not be saved.');
        }
    },

    async update(scheduleId, payload) {
        try {
            const { data } = await http.put(`/api/schedules/${scheduleId}`, payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The schedule could not be saved.');
        }
    },

    async destroy(scheduleId) {
        try {
            await http.delete(`/api/schedules/${scheduleId}`);
        } catch (error) {
            throw normalizeError(error, 'The schedule could not be removed.');
        }
    },

    async runNow(scheduleId) {
        try {
            const { data } = await http.post(`/api/schedules/${scheduleId}/run`);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The schedule could not be queued.');
        }
    },
};

export default scheduleService;
