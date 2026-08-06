import http, { normalizeError, queryParams } from './http';

/**
 * User administration and the audit trail.
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

    async updateUser(userId, payload) {
        try {
            const { data } = await http.put(`/api/admin/users/${userId}`, payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'The user access settings could not be saved.');
        }
    },

    async audit({ page = 1, ...filters } = {}) {
        try {
            const { data } = await http.get('/api/admin/audit', {
                params: queryParams({ page, ...filters }),
            });

            return data;
        } catch (error) {
            throw normalizeError(
                error,
                'Audit events could not be loaded.',
                'the audit trail',
            );
        }
    },
};

export default adminService;
