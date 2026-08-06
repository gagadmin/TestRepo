import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useAuthStore } from './authStore';
import { authService } from '@/services/authService';

vi.mock('@/services/authService', () => ({
    authService: {
        bootstrap: vi.fn(),
        login: vi.fn(),
        logout: vi.fn(),
    },
}));

describe('authStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    const platform = {
        user: {
            id: 7,
            name: 'Jacob',
            department: 'Information Technology',
            permissions: ['dashboards.view', 'security.view'],
            roles: [{ name: 'administrator', label: 'Administrator' }],
        },
        phases: [{ name: 'Discovery', progress: 100 }],
    };

    it('is unauthenticated until bootstrap returns a platform', async () => {
        authService.bootstrap.mockResolvedValue(null);
        const auth = useAuthStore();

        await auth.bootstrap();

        expect(auth.isAuthenticated).toBe(false);
        expect(auth.permissions).toEqual([]);
        expect(auth.loading).toBe(false);
    });

    it('exposes user, permissions and roles after bootstrap', async () => {
        authService.bootstrap.mockResolvedValue(platform);
        const auth = useAuthStore();

        await auth.bootstrap();

        expect(auth.isAuthenticated).toBe(true);
        expect(auth.user.name).toBe('Jacob');
        expect(auth.phases).toHaveLength(1);
    });

    it('can() is the single permission check', async () => {
        authService.bootstrap.mockResolvedValue(platform);
        const auth = useAuthStore();
        await auth.bootstrap();

        expect(auth.can('security.view')).toBe(true);
        expect(auth.can('security.manage')).toBe(false);
        // A route with no permission requirement is always allowed.
        expect(auth.can(null)).toBe(true);
        expect(auth.canAny(['nope', 'security.view'])).toBe(true);
        expect(auth.canAny(['nope'])).toBe(false);
        expect(auth.canAny([])).toBe(true);
    });

    it('hasRole matches role objects and plain names', async () => {
        authService.bootstrap.mockResolvedValue(platform);
        const auth = useAuthStore();
        await auth.bootstrap();

        expect(auth.hasRole('administrator')).toBe(true);
        expect(auth.hasRole('security_officer')).toBe(false);
    });

    it('login populates the platform and reports success', async () => {
        authService.login.mockResolvedValue(undefined);
        authService.bootstrap.mockResolvedValue(platform);
        const auth = useAuthStore();

        const result = await auth.login({ email: 'a@b.com', password: 'x' });

        expect(result.ok).toBe(true);
        expect(auth.isAuthenticated).toBe(true);
        expect(auth.submitting).toBe(false);
    });

    it('login surfaces the failure message and stays unauthenticated', async () => {
        authService.login.mockRejectedValue(
            Object.assign(new Error('Sign-in failed. Check your credentials.'), { status: 422 }),
        );
        const auth = useAuthStore();

        const result = await auth.login({ email: 'a@b.com', password: 'wrong' });

        expect(result.ok).toBe(false);
        expect(auth.error).toBe('Sign-in failed. Check your credentials.');
        expect(auth.isAuthenticated).toBe(false);
    });

    it('clears the platform even if the logout request fails', async () => {
        authService.bootstrap.mockResolvedValue(platform);
        authService.logout.mockRejectedValue(new Error('network'));
        const auth = useAuthStore();
        await auth.bootstrap();

        await expect(auth.logout()).rejects.toThrow('network');
        expect(auth.isAuthenticated).toBe(false);
    });

    it('session expiry clears state and explains why', async () => {
        authService.bootstrap.mockResolvedValue(platform);
        const auth = useAuthStore();
        await auth.bootstrap();

        auth.handleSessionExpiry();

        expect(auth.isAuthenticated).toBe(false);
        expect(auth.error).toContain('session has ended');
    });
});
