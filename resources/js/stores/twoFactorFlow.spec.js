import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useAuthStore } from './authStore';
import { authService } from '@/services/authService';

vi.mock('@/services/authService', () => ({
    authService: {
        bootstrap: vi.fn(),
        login: vi.fn(),
        verifyTwoFactor: vi.fn(),
        cancelTwoFactor: vi.fn(),
        logout: vi.fn(),
    },
}));

/**
 * Client-side half of the two-step sign-in.
 *
 * These assertions guard the property that the UI must never treat a correct
 * password as a completed sign-in.
 */
describe('two-step sign-in', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    const platform = {
        user: { id: 1, name: 'Jacob', permissions: [], roles: [] },
        security: {
            two_factor: { enabled: true, required: true, recovery_codes_remaining: 8 },
            password: { must_change: false, age_days: 3 },
        },
    };

    it('does not authenticate on a password alone when a challenge is returned', async () => {
        authService.login.mockResolvedValue({
            two_factor_required: true,
            recovery_codes_available: true,
        });
        const auth = useAuthStore();

        const result = await auth.login({ email: 'a@b.com', password: 'x' });

        expect(result).toEqual({ ok: true, twoFactorRequired: true });
        expect(auth.twoFactorPending).toBe(true);
        expect(auth.recoveryCodesAvailable).toBe(true);
        // The decisive assertion: still not signed in.
        expect(auth.isAuthenticated).toBe(false);
        expect(authService.bootstrap).not.toHaveBeenCalled();
    });

    it('completes sign-in once the code verifies', async () => {
        authService.login.mockResolvedValue({ two_factor_required: true });
        authService.verifyTwoFactor.mockResolvedValue({ message: 'Signed in successfully.' });
        authService.bootstrap.mockResolvedValue(platform);
        const auth = useAuthStore();

        await auth.login({ email: 'a@b.com', password: 'x' });
        const result = await auth.verifyTwoFactor('123456');

        expect(result.ok).toBe(true);
        expect(auth.twoFactorPending).toBe(false);
        expect(auth.isAuthenticated).toBe(true);
    });

    it('keeps the challenge open after a wrong code', async () => {
        authService.login.mockResolvedValue({ two_factor_required: true });
        authService.verifyTwoFactor.mockRejectedValue(
            Object.assign(new Error('That code is not valid or has already been used.'), { status: 422 }),
        );
        const auth = useAuthStore();

        await auth.login({ email: 'a@b.com', password: 'x' });
        const result = await auth.verifyTwoFactor('000000');

        expect(result.ok).toBe(false);
        expect(result.restart).toBe(false);
        // Still on the code step so the user can retry.
        expect(auth.twoFactorPending).toBe(true);
        expect(auth.isAuthenticated).toBe(false);
    });

    it('restarts from the password step when the challenge expired', async () => {
        authService.login.mockResolvedValue({ two_factor_required: true });
        authService.verifyTwoFactor.mockRejectedValue(
            Object.assign(new Error('That sign-in attempt expired.'), { status: 410, expired: true }),
        );
        const auth = useAuthStore();

        await auth.login({ email: 'a@b.com', password: 'x' });
        const result = await auth.verifyTwoFactor('123456');

        expect(result.restart).toBe(true);
        expect(auth.twoFactorPending).toBe(false);
    });

    it('surfaces a lockout with its retry hint', async () => {
        authService.login.mockRejectedValue(
            Object.assign(new Error('Too many failed attempts. Try again in about 5 minutes.'), {
                status: 423,
                locked: true,
                retryAfterSeconds: 300,
            }),
        );
        const auth = useAuthStore();

        const result = await auth.login({ email: 'a@b.com', password: 'wrong' });

        expect(result.ok).toBe(false);
        expect(result.locked).toBe(true);
        expect(auth.lockedForSeconds).toBe(300);
        expect(auth.isAuthenticated).toBe(false);
    });

    it('cancelling clears the pending state', async () => {
        authService.login.mockResolvedValue({ two_factor_required: true });
        const auth = useAuthStore();

        await auth.login({ email: 'a@b.com', password: 'x' });
        await auth.cancelTwoFactor();

        expect(auth.twoFactorPending).toBe(false);
        expect(authService.cancelTwoFactor).toHaveBeenCalled();
    });

    it('signs in directly when the account has no second factor', async () => {
        authService.login.mockResolvedValue({
            two_factor_required: false,
            two_factor_setup_required: true,
        });
        authService.bootstrap.mockResolvedValue({
            ...platform,
            security: {
                two_factor: { enabled: false, required: true, recovery_codes_remaining: 0 },
                password: { must_change: false, age_days: null },
            },
        });
        const auth = useAuthStore();

        const result = await auth.login({ email: 'a@b.com', password: 'x' });

        expect(result.twoFactorRequired).toBe(false);
        expect(auth.isAuthenticated).toBe(true);
        // The router will now send them to enrolment.
        expect(auth.mustEnrolTwoFactor).toBe(true);
    });
});

describe('identity gating flags', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    const build = (twoFactor, password) => ({
        user: { id: 1, name: 'Jacob', permissions: [], roles: [] },
        security: { two_factor: twoFactor, password },
    });

    it('requires enrolment only when required and not yet enabled', async () => {
        authService.bootstrap.mockResolvedValue(build(
            { enabled: false, required: true, recovery_codes_remaining: 0 },
            { must_change: false, age_days: 1 },
        ));
        const auth = useAuthStore();
        await auth.bootstrap();

        expect(auth.mustEnrolTwoFactor).toBe(true);
        expect(auth.mustChangePassword).toBe(false);
    });

    it('does not require enrolment once enabled', async () => {
        authService.bootstrap.mockResolvedValue(build(
            { enabled: true, required: true, recovery_codes_remaining: 8 },
            { must_change: false, age_days: 1 },
        ));
        const auth = useAuthStore();
        await auth.bootstrap();

        expect(auth.mustEnrolTwoFactor).toBe(false);
    });

    it('requires a password change when the server says so', async () => {
        authService.bootstrap.mockResolvedValue(build(
            { enabled: true, required: true, recovery_codes_remaining: 8 },
            { must_change: true, age_days: 400 },
        ));
        const auth = useAuthStore();
        await auth.bootstrap();

        expect(auth.mustChangePassword).toBe(true);
    });

    it('reports no gates for an unauthenticated visitor', () => {
        const auth = useAuthStore();

        expect(auth.mustEnrolTwoFactor).toBe(false);
        expect(auth.mustChangePassword).toBe(false);
    });

    it('tolerates a bootstrap payload without the security block', async () => {
        authService.bootstrap.mockResolvedValue({
            user: { id: 1, name: 'Jacob', permissions: [], roles: [] },
        });
        const auth = useAuthStore();
        await auth.bootstrap();

        expect(auth.twoFactor.enabled).toBe(false);
        expect(auth.mustEnrolTwoFactor).toBe(false);
    });
});
