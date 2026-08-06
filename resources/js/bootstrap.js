import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.headers.common['Accept'] = 'application/json';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;

/**
 * Route the identity gates to the right screen.
 *
 * Business endpoints sit behind the `mfa` and `password.current` middleware, so
 * a session that has not enrolled a second factor (or must change its password)
 * receives 403 with a machine-readable `code`. Without this, callers report it
 * as a permissions failure — "you do not have permission to view users" — which
 * sends people hunting a role problem that does not exist.
 *
 * Registered on the global instance because the un-migrated legacy views use it
 * directly. `services/http.js` covers the extracted code.
 */
const GATE_DESTINATIONS = {
    two_factor_setup_required: '/security/two-factor-setup',
    password_change_required: '/account/password',
};

window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const code = error?.response?.data?.code;
        const destination = error?.response?.status === 403
            ? GATE_DESTINATIONS[code]
            : undefined;

        // Guard against a redirect loop if the gate page itself is refused.
        if (destination && window.location.pathname !== destination) {
            window.location.assign(destination);
        }

        return Promise.reject(error);
    },
);
