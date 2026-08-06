import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';
import { useUiStore } from '@/stores/uiStore';
import { identityGateRedirect } from './identityGate';
import routes from './routes';

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (to, from, saved) => saved ?? { top: 0 },
});

/**
 * Authentication and authorization guard.
 *
 * Runs once per navigation and is the only gate in the frontend. Note this
 * mirrors, but does not replace, the server-side checks: every API route is
 * independently protected by permission middleware, so a user who forges a
 * URL gets a 403 from the backend regardless of what the router allows.
 */
router.beforeEach(async (to) => {
    const auth = useAuthStore();
    const ui = useUiStore();

    // Navigating always dismisses the mobile sidebar.
    ui.closeSidebar();

    // Resolve the session once on first navigation.
    if (auth.platform === null && auth.loading) {
        await auth.bootstrap();
    }

    if (to.meta.public) {
        // Already signed in — no reason to show the login screen.
        return auth.isAuthenticated ? { name: 'overview' } : true;
    }

    if (!auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    /*
     * Identity gates, checked in the same order the server enforces them.
     * The API returns 403 with a machine-readable code regardless of what the
     * router does; this only avoids showing the user a bare error.
     *
     * Password change is checked first: a compromised password should be
     * replaced before the account does anything else, including enrolling a
     * second factor against it.
     */
    const gateRedirect = identityGateRedirect(auth, to);

    if (gateRedirect) {
        return gateRedirect;
    }

    // Once satisfied, the gate pages themselves are no longer reachable.
    if (to.meta.gate) {
        if (to.name === 'change-password' && !auth.mustChangePassword) {
            // Self-service change stays available; only the forced flow redirects.
            return true;
        }

        if (to.name === 'two-factor-setup' && !auth.mustEnrolTwoFactor) {
            return { name: 'overview' };
        }
    }

    if (to.meta.permission && !auth.can(to.meta.permission)) {
        // Send them somewhere they can actually use rather than a dead end.
        return { name: 'overview', query: { denied: to.name } };
    }

    return true;
});

router.afterEach((to) => {
    const base = 'Ask GAHolding';
    document.title = to.meta.title ? `${to.meta.title} · ${base}` : base;
});

export default router;
