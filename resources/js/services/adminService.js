import http, { normalizeError, queryParams } from './http';

/**
 * User administration.
 *
 * The audit trail lives in `auditService`: it is governance evidence with its
 * own permission and its own screen, not a facet of user management.
 */
export const adminService = {
    async users({ page = 1, search = '' } = {}) {
        try {
            const { data } = await http.get('/api/admin/users', {
                params: queryParams({ page, search: search.trim() }),
            });

            return data;
        } catch (error) {
            throw normalizeError(
                error,
                'Users and access settings could not be loaded.',
                'users',
            );
        }
    },

    async createUser(payload) {
        try {
            const { data } = await http.post('/api/admin/users', payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The account could not be created.');
        }
    },

    async updateUser(userId, payload) {
        try {
            const { data } = await http.put(`/api/admin/users/${userId}`, payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The user access settings could not be saved.');
        }
    },
};

export default adminService;
