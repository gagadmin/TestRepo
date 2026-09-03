import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { useNavigation } from './useNavigation';
import { useAuthStore } from '@/stores/authStore';

/*
 * Covers NG-01 in
 * ai/test-cases/role-based-navigation-and-configurable-dashboard-access.md:
 * an entry the user cannot open must be absent, not merely disabled.
 */
const routes = [
    { name: 'overview', meta: { nav: 'workspace', title: 'Overview', order: 10 } },
    { name: 'dashboards', meta: { nav: 'workspace', title: 'Dashboards', order: 30, permission: 'dashboards.view' } },
    { name: 'analytics', meta: { nav: 'workspace', title: 'Advanced analytics', order: 60, permission: 'analytics.view' } },
    { name: 'users', meta: { nav: 'admin', title: 'Users & access', order: 20, permission: 'users.view' } },
    { name: 'login', meta: { public: true, title: 'Sign in' } },
];

vi.mock('vue-router', () => ({
    useRouter: () => ({ getRoutes: () => routes }),
}));

vi.mock('@/services/authService', () => ({
    authService: { bootstrap: vi.fn(), login: vi.fn(), logout: vi.fn() },
}));

function signIn(permissions) {
    const auth = useAuthStore();
    auth.platform = {
        user: { id: 1, name: 'QA', permissions },
    };

    return auth;
}

describe('useNavigation', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('omits entries the user has no permission to open', () => {
        signIn(['dashboards.view']);

        const { workspaceItems } = useNavigation();

        expect(workspaceItems.value.map((item) => item.name)).toEqual(['overview', 'dashboards']);
    });

    it('returns an empty group rather than disabled entries', () => {
        signIn([]);

        const { adminItems } = useNavigation();

        expect(adminItems.value).toEqual([]);
    });

    it('keeps routes without a permission requirement', () => {
        signIn([]);

        const { workspaceItems } = useNavigation();

        expect(workspaceItems.value.map((item) => item.name)).toEqual(['overview']);
    });

    it('orders entries by their declared order', () => {
        signIn(['dashboards.view', 'analytics.view', 'users.view']);

        const { workspaceItems, adminItems } = useNavigation();

        expect(workspaceItems.value.map((item) => item.name)).toEqual([
            'overview',
            'dashboards',
            'analytics',
        ]);
        expect(adminItems.value.map((item) => item.name)).toEqual(['users']);
    });
});
