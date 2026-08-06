import { describe, expect, it } from 'vitest';
import { ApiError, normalizeError, queryParams } from './http';

/**
 * These assertions pin down the error precedence that was previously
 * reimplemented (inconsistently) in fifteen separate loader functions.
 */
describe('normalizeError', () => {
    const build = (status, data) => ({ response: { status, data } });

    it('prefers a validation message over the generic message', () => {
        const error = normalizeError(
            build(422, {
                message: 'The given data was invalid.',
                errors: { resolution_note: ['Explain how this was resolved.'] },
            }),
            'fallback',
        );

        expect(error.message).toBe('Explain how this was resolved.');
        expect(error.isValidation).toBe(true);
        expect(error.firstValidationMessage).toBe('Explain how this was resolved.');
    });

    it('builds a permission message from the resource name on 403', () => {
        const error = normalizeError(build(403, {}), 'fallback', 'the audit trail');

        expect(error.message).toBe('Your account does not have permission to view the audit trail.');
        expect(error.isForbidden).toBe(true);
    });

    it('uses the server message on 403 when no resource name is given', () => {
        const error = normalizeError(build(403, { message: 'Restricted to IT.' }), 'fallback');

        expect(error.message).toBe('Restricted to IT.');
    });

    it('falls back to the supplied default when the server offers nothing', () => {
        const error = normalizeError(build(500, {}), 'Reports could not be loaded.');

        expect(error.message).toBe('Reports could not be loaded.');
        expect(error.status).toBe(500);
    });

    it('flags an unauthenticated response', () => {
        expect(normalizeError(build(401, {}), 'x').isUnauthenticated).toBe(true);
    });

    it('handles a network error with no response object', () => {
        const error = normalizeError(new Error('Network Error'), 'Could not reach the server.');

        expect(error).toBeInstanceOf(ApiError);
        expect(error.message).toBe('Could not reach the server.');
        expect(error.status).toBeNull();
    });
});

describe('queryParams', () => {
    it('drops empty, null and undefined values but keeps zero and false', () => {
        expect(queryParams({
            page: 1,
            search: '',
            status: null,
            detector: undefined,
            limit: 0,
            active: false,
        })).toEqual({ page: 1, limit: 0, active: false });
    });

    it('returns an empty object for no input', () => {
        expect(queryParams()).toEqual({});
    });
});
