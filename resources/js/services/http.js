import axios from 'axios';

/**
 * The single HTTP client for the application.
 *
 * Every request goes through here so the session/CSRF configuration, error
 * shape, and unauthenticated handling are defined in exactly one place.
 * Components and pages must never import axios directly.
 */
const http = axios.create({
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
});

/**
 * Callbacks invoked when the server reports the session is gone. The auth
 * store registers itself here, which avoids a circular import between the
 * client and the store.
 */
const unauthenticatedHandlers = new Set();

export function onUnauthenticated(handler) {
    unauthenticatedHandlers.add(handler);

    return () => unauthenticatedHandlers.delete(handler);
}

/**
 * A normalised error the UI can render directly.
 *
 * The previous implementation repeated this unwrapping in every one of the
 * ~15 loader functions, each with slightly different precedence. Centralising
 * it means one behaviour everywhere.
 */
export class ApiError extends Error {
    constructor(message, { status = null, errors = {}, cause = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
        this.cause = cause;
    }

    /** True when the server rejected the payload (422). */
    get isValidation() {
        return this.status === 422;
    }

    get isForbidden() {
        return this.status === 403;
    }

    get isUnauthenticated() {
        return this.status === 401;
    }

    /** First validation message, if any — what forms usually want to show. */
    get firstValidationMessage() {
        return Object.values(this.errors).flat()[0] ?? null;
    }
}

/**
 * Turn any axios failure into an ApiError with a human-readable message.
 *
 * @param {unknown} error       The caught error.
 * @param {string}  fallback    Message used when the server offers none.
 * @param {string} [resource]   Used to build the 403 message, e.g. "the audit trail".
 */
export function normalizeError(error, fallback, resource = null) {
    const status = error?.response?.status ?? null;
    const payload = error?.response?.data ?? {};
    const errors = payload.errors ?? {};

    if (status === 403) {
        /*
         * An identity gate is not a permissions problem. The mfa and
         * password.current middleware both answer 403, but with a
         * machine-readable `code`. Reporting these as "you do not have
         * permission" sends people hunting a role issue that does not exist.
         */
        if (payload.code === 'two_factor_setup_required' || payload.code === 'password_change_required') {
            const gate = new ApiError(payload.message, { status, errors, cause: error });
            gate.gateCode = payload.code;

            return gate;
        }

        return new ApiError(
            resource
                ? `Your account does not have permission to view ${resource}.`
                : payload.message ?? 'You do not have permission to perform this action.',
            { status, errors, cause: error },
        );
    }

    const message = Object.values(errors).flat()[0]
        ?? payload.message
        ?? fallback;

    return new ApiError(message, { status, errors, cause: error });
}

// A 401 anywhere means the session ended; tell the auth store once.
http.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401) {
            unauthenticatedHandlers.forEach((handler) => handler());
        }

        return Promise.reject(error);
    },
);

/**
 * Strip empty values from a query object.
 *
 * Several views built params with an inline
 * `Object.fromEntries(Object.entries(x).filter(...))`. This replaces all of them.
 */
export function queryParams(source = {}) {
    return Object.fromEntries(
        Object.entries(source).filter(([, value]) => (
            value !== '' && value !== null && value !== undefined
        )),
    );
}

export default http;
