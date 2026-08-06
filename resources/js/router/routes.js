/**
 * Route table.
 *
 * `meta.permission` drives both the navigation guard and the sidebar, so a
 * route's authorization requirement is declared exactly once. The previous
 * implementation duplicated it: once in `navItems`/`adminItems` and again in
 * each view's error handling.
 *
 * Every page component is lazily imported, so a user who never opens Security
 * never downloads it. App.vue previously shipped all ten views in one chunk.
 */
export const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/LoginPage.vue'),
        meta: { public: true, layout: 'auth', title: 'Sign in' },
    },
    {
        // Mandatory enrolment. `gate: true` marks routes that must stay
        // reachable while the session is otherwise confined.
        path: '/security/two-factor-setup',
        name: 'two-factor-setup',
        component: () => import('@/pages/TwoFactorSetupPage.vue'),
        meta: { title: 'Set up two-step verification', gate: true, layout: 'gate' },
    },
    {
        path: '/account/password',
        name: 'change-password',
        component: () => import('@/pages/ChangePasswordPage.vue'),
        meta: { title: 'Change your password', gate: true, layout: 'gate' },
    },
    {
        // Self-service account security. No permission: every signed-in user
        // may manage their own password and second factor.
        path: '/account',
        name: 'profile',
        component: () => import('@/pages/ProfilePage.vue'),
        meta: { title: 'Profile & security' },
    },
    {
        path: '/',
        name: 'overview',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/OverviewPage.vue
        meta: {
            layout: 'legacy', title: 'Overview', nav: 'workspace', icon: 'pi-home', order: 10 },
    },
    {
        path: '/ai',
        name: 'ai',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/AiWorkspacePage.vue
        meta: {
            layout: 'legacy',
            permission: 'ai.chat',
            title: 'Ask GAHolding',
            nav: 'workspace',
            icon: 'pi-sparkles',
            order: 20,
        },
    },
    {
        path: '/dashboards',
        name: 'dashboards',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/DashboardsPage.vue
        meta: {
            layout: 'legacy',
            permission: 'dashboards.view',
            title: 'Dashboards',
            nav: 'workspace',
            icon: 'pi-chart-bar',
            order: 30,
        },
    },
    {
        // Deep-linkable dashboard. Resolves KI-012 (no browser history or
        // shareable dashboard URLs).
        path: '/dashboards/:slug',
        name: 'dashboard',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/DashboardsPage.vue
        meta: {
            layout: 'legacy', permission: 'dashboards.view', title: 'Dashboards' },
        props: true,
    },
    {
        path: '/reports',
        name: 'reports',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/ReportsPage.vue
        meta: {
            layout: 'legacy',
            permission: 'reports.view',
            title: 'Reports',
            nav: 'workspace',
            icon: 'pi-file',
            order: 40,
        },
    },
    {
        path: '/reports/:reportId',
        name: 'report',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/ReportsPage.vue
        meta: {
            layout: 'legacy', permission: 'reports.view', title: 'Reports' },
        props: true,
    },
    {
        path: '/schedules',
        name: 'schedules',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/SchedulesPage.vue
        meta: {
            layout: 'legacy',
            permission: 'reports.schedule',
            title: 'Schedules',
            nav: 'workspace',
            icon: 'pi-clock',
            order: 50,
        },
    },
    {
        path: '/analytics',
        name: 'analytics',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/AnalyticsPage.vue
        meta: {
            layout: 'legacy',
            permission: 'analytics.view',
            title: 'Advanced analytics',
            nav: 'workspace',
            icon: 'pi-chart-line',
            order: 60,
        },
    },
    {
        path: '/seo',
        name: 'seo',
        component: () => import('@/pages/SeoInsightsPage.vue'),
        meta: {
            permission: 'seo.view',
            title: 'SEO insights',
            nav: 'workspace',
            icon: 'pi-search',
            order: 70,
        },
    },
    {
        path: '/integrations',
        name: 'integrations',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/IntegrationsPage.vue
        meta: {
            layout: 'legacy',
            permission: 'integrations.manage',
            title: 'Data sources',
            nav: 'admin',
            icon: 'pi-database',
            order: 10,
        },
    },
    {
        path: '/users',
        name: 'users',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/UsersPage.vue
        meta: {
            layout: 'legacy',
            permission: 'users.view',
            title: 'Users & access',
            nav: 'admin',
            icon: 'pi-users',
            order: 20,
        },
    },
    {
        path: '/ai-tools',
        name: 'ai-tools',
        component: () => import('@/pages/AiToolsPage.vue'),
        meta: {
            permission: 'integrations.manage',
            title: 'AI tools',
            nav: 'admin',
            icon: 'pi-wrench',
            order: 15,
        },
    },
    {
        path: '/security',
        name: 'security',
        component: () => import('@/pages/SecurityPage.vue'),
        meta: {
            permission: 'security.view',
            title: 'Security',
            nav: 'admin',
            icon: 'pi-lock',
            order: 30,
        },
    },
    {
        path: '/audit',
        name: 'audit',
        component: () => import('@/pages/LegacyWorkspacePage.vue'),
        // TODO(migration): extract into pages/AuditPage.vue
        meta: {
            layout: 'legacy',
            permission: 'audit.view',
            title: 'Audit trail',
            nav: 'admin',
            icon: 'pi-shield',
            order: 40,
        },
    },
    {
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        component: () => import('@/pages/NotFoundPage.vue'),
        meta: { title: 'Not found' },
    },
];

export default routes;
