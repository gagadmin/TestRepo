import http, { normalizeError } from './http';

/**
 * Authentication and platform bootstrap.
 */
export const authService = {
    async bootstrap() {
        try {
            const { data } = await http.get('/api/bootstrap');

            return data;
        } catch (error) {
            // A 401 here is expected before sign-in and is not an error state.
            if (error?.response?.status === 401) {
                return null;
            }

            throw normalizeError(error, 'The platform could not be loaded. Please try again.');
        }
    },

    /**
     * Step one: password.
     *
     * Resolves with `two_factor_required` when the account is enrolled — no
     * session exists at that point, so the caller must complete the challenge.
     */
    async login(credentials) {
        try {
            const { data } = await http.post('/auth/login', credentials);

            return data;
        } catch (error) {
            const normalized = normalizeError(error, 'Sign-in failed. Check your credentials.');

            // 423 Locked carries a specific retry hint, distinct from a 429.
            if (error?.response?.status === 423) {
                normalized.locked = true;
                normalized.retryAfterSeconds = error.response.data?.retry_after_seconds ?? null;

                throw normalized;
            }

            // Laravel returns the auth failure under errors.email.
            throw new (normalized.constructor)(
                error?.response?.data?.errors?.email?.[0] ?? normalized.message,
                { status: normalized.status, errors: normalized.errors, cause: error },
            );
        }
    },

    /**
     * Step two: the six-digit code or a recovery code.
     */
    async verifyTwoFactor(code) {
        try {
            const { data } = await http.post('/auth/two-factor', { code });

            return data;
        } catch (error) {
            const normalized = normalizeError(
                error,
                'That code could not be verified.',
            );

            if (error?.response?.status === 410) {
                normalized.expired = true;
            }

            if (error?.response?.status === 423) {
                normalized.locked = true;
                normalized.retryAfterSeconds = error.response.data?.retry_after_seconds ?? null;
            }

            normalized.message = error?.response?.data?.errors?.code?.[0] ?? normalized.message;

            throw normalized;
        }
    },

    async cancelTwoFactor() {
        try {
            await http.post('/auth/two-factor/cancel');
        } catch {
            // Best effort: the pending record expires on its own.
        }
    },

    async logout() {
        await http.post('/auth/logout');
    },
};

export default authService;
