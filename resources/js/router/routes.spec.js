import { describe, expect, it } from 'vitest';
import routes from './routes';

/**
 * Guards the route table itself. These assertions are what stop the migration
 * from silently dropping a permission check or orphaning a nav entry.
 */
describe('route table', () => {
    const named = routes.filter((route) => route.name && route.name !== 'not-found');

    it('gives every route a unique name and path', () => {
        const names = routes.map((route) => route.name);
        const paths = routes.map((route) => route.path);

        expect(new Set(names).size).toBe(names.length);
        expect(new Set(paths).size).toBe(paths.length);
    });

    it('protects every non-public route except self-service identity pages and overview', () => {
        const unprotected = named
            .filter((route) => !route.meta?.public)
            .filter((route) => !route.meta?.permission)
            .map((route) => route.name)
            .sort();

        /*
         * Overview is the post-login landing page and the guard's fallback.
         *
         * The two identity gates carry no permission by design: they must stay
         * reachable precisely when the session is confined, and they are the
         * only way to satisfy the requirement that confines it. Their server
         * counterparts are likewise outside the mfa/password.current group.
         * Profile is authenticated self-service and relies on its endpoint
         * authorization rather than a role permission.
         */
        expect(unprotected).toEqual(['change-password', 'overview', 'profile', 'two-factor-setup']);
    });

    it('marks the identity gates so the guard can keep them reachable', () => {
        const gates = routes.filter((route) => route.meta?.gate).map((route) => route.name).sort();

        expect(gates).toEqual(['change-password', 'two-factor-setup']);
    });

    it('declares a title for every route so the tab name is never blank', () => {
        routes.forEach((route) => {
            expect(route.meta?.title, `${route.name} is missing meta.title`).toBeTruthy();
        });
    });

    it('gives every navigable route an icon and an order', () => {
        named
            .filter((route) => route.meta?.nav)
            .forEach((route) => {
                expect(route.meta.icon, `${route.name} is missing meta.icon`).toBeTruthy();
                expect(typeof route.meta.order, `${route.name} is missing meta.order`).toBe('number');
            });
    });

    it('keeps nav ordering unique within each group', () => {
        ['workspace', 'admin'].forEach((group) => {
            const orders = named
                .filter((route) => route.meta?.nav === group)
                .map((route) => route.meta.order);

            expect(new Set(orders).size, `${group} has duplicate order values`).toBe(orders.length);
        });
    });

    it('has a catch-all last so it cannot shadow a real route', () => {
        expect(routes.at(-1).name).toBe('not-found');
    });

    it('marks only the login route public', () => {
        const publicRoutes = routes.filter((route) => route.meta?.public).map((route) => route.name);

        expect(publicRoutes).toEqual(['login']);
    });

    /**
     * Migration tracker. Every route still pointing at the monolith carries the
     * 'legacy' layout. As views are extracted this number falls to zero, at
     * which point LegacyWorkspacePage and LegacyLayout can be deleted.
     */
    it('reports how many routes remain on the legacy monolith', () => {
        const legacy = routes.filter((route) => route.meta?.layout === 'legacy');

        // Update this expectation as each view is migrated — it is a deliberate
        // checklist, not an assertion about correctness.
        expect(legacy.length).toBe(9);
        expect(routes.find((route) => route.name === 'security').meta.layout).toBeUndefined();
    });
});
