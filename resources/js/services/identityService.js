import http, { normalizeError } from './http';

/**
 * Second-factor enrolment and password self-service.
 */
export const identityService = {
    /* ---- Two-factor ---- */

    async twoFactorStatus() {
        try {
            const { data } = await http.get('/api/two-factor');

            return data;
        } catch (error) {
            throw normalizeError(error, 'Two-factor status could not be loaded.');
        }
    },

    async beginSetup() {
        try {
            const { data } = await http.post('/api/two-factor/setup');

            return data;
        } catch (error) {
            throw normalizeError(error, 'Two-factor setup could not be started.');
        }
    },

    async confirmSetup(code) {
        try {
            const { data } = await http.post('/api/two-factor/confirm', { code });

            return data;
        } catch (error) {
            throw normalizeError(error, 'That code could not be confirmed.');
        }
    },

    async regenerateRecoveryCodes(currentPassword) {
        try {
            const { data } = await http.post('/api/two-factor/recovery-codes', {
                current_password: currentPassword,
            });

            return data;
        } catch (error) {
            throw normalizeError(error, 'New recovery codes could not be issued.');
        }
    },

    async disableTwoFactor(currentPassword) {
        try {
            const { data } = await http.delete('/api/two-factor', {
                data: { current_password: currentPassword },
            });

            return data;
        } catch (error) {
            throw normalizeError(error, 'Two-factor authentication could not be turned off.');
        }
    },

    /* ---- Password ---- */

    async passwordPolicy() {
        try {
            const { data } = await http.get('/api/account/password/policy');

            return data;
        } catch (error) {
            throw normalizeError(error, 'The password policy could not be loaded.');
        }
    },

    async changePassword(payload) {
        try {
            const { data } = await http.put('/api/account/password', payload);

            return data;
        } catch (error) {
            throw normalizeError(error, 'Your password could not be changed.');
        }
    },
};

export default identityService;
