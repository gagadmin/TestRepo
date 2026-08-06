import { describe, expect, it } from 'vitest';
import { identityGateRedirect } from './identityGate';

describe('identity gate ordering', () => {
    it('keeps a newly provisioned user on password change when both gates apply', () => {
        const auth = { mustChangePassword: true, mustEnrolTwoFactor: true };

        expect(identityGateRedirect(auth, { name: 'overview' }))
            .toEqual({ name: 'change-password' });
        expect(identityGateRedirect(auth, { name: 'change-password' })).toBeNull();
    });

    it('moves to MFA enrolment after password change is complete', () => {
        const auth = { mustChangePassword: false, mustEnrolTwoFactor: true };

        expect(identityGateRedirect(auth, { name: 'overview' }))
            .toEqual({ name: 'two-factor-setup' });
        expect(identityGateRedirect(auth, { name: 'two-factor-setup' })).toBeNull();
    });

    it('does not redirect after both identity requirements are satisfied', () => {
        const auth = { mustChangePassword: false, mustEnrolTwoFactor: false };

        expect(identityGateRedirect(auth, { name: 'overview' })).toBeNull();
    });
});
