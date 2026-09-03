<script setup>
/**
 * LEGACY WORKSPACE - SCHEDULED FOR REMOVAL.
 *
 * This is the pre-refactor monolith, retained verbatim so the nine views not
 * yet migrated keep working while they are extracted one at a time. It still
 * owns its own chrome (loading screen, login, sidebar, topbar), so it is routed
 * with meta.layout = 'legacy' and rendered bare by App.vue rather than inside
 * AppLayout - otherwise the chrome would render twice.
 *
 * Do not add features here. Each migration moves one currentView block into
 * pages/, deletes it from this file, and repoints its route. When the last
 * block is gone, delete this file and the 'legacy' branch in App.vue.
 *
 * Migrated:  security -> pages/SecurityPage.vue
 * Remaining: overview, ai, dashboards, reports, schedules, analytics,
 *            integrations, users, audit
 *
 * See docs/frontend-architecture.md for the procedure.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import Calendar from 'primevue/calendar';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Message from 'primevue/message';
import MultiSelect from 'primevue/multiselect';
import Password from 'primevue/password';
import ProgressBar from 'primevue/progressbar';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import UserAccountLink from '@/components/layout/UserAccountLink.vue';
import { formatLatency, renderAnswer } from '@/composables/useAnswerFormat';

const loading = ref(true);
const submitting = ref(false);
const loginError = ref('');
const platform = ref(null);
const sidebarOpen = ref(false);
const currentView = ref('overview');
const integrations = ref({ data: [], types: [], auth_types: [] });
const integrationsLoading = ref(false);
const integrationError = ref('');
const adminUsers = ref({
    data: [],
    roles: [],
    departments: [],
    data_sources: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
});
const adminUsersLoading = ref(false);
const adminUsersError = ref('');
const adminUserSearch = ref('');
const userAccessDialogOpen = ref(false);
const userAccessSaving = ref(false);
const editingUserId = ref(null);
const userAccessForm = ref({
    name: '',
    email: '',
    department: '',
    title: '',
    is_active: true,
    roles: [],

    /*
     * Access profile. `allowed_departments` is the multi-department grant that
     * replaces inferring visibility from the single department label.
     * `restrict_data_sources` distinguishes "no platform restriction" (send
     * null) from "these platforms only" (send the list) — the two mean
     * different things to the server and must not collapse into one another.
     */
    allowed_departments: [],
    restrict_data_sources: false,
    allowed_data_source_ids: [],
});

/* --- Create user -------------------------------------------------------- */

const createUserDialogOpen = ref(false);
const createUserSaving = ref(false);
const createUserError = ref('');
// Returned once by the API and never retrievable again.
const createdUserCredentials = ref(null);
const createUserForm = ref(emptyCreateUserForm());

function emptyCreateUserForm() {
    return {
        name: '',
        email: '',
        department: '',
        title: '',
        is_active: true,
        roles: ['executive'],
        allowed_departments: [],
        restrict_data_sources: false,
        allowed_data_source_ids: [],
    };
}

function openCreateUser() {
    createUserForm.value = emptyCreateUserForm();
    createdUserCredentials.value = null;
    createUserError.value = '';
    createUserDialogOpen.value = true;
}

async function saveNewUser() {
    createUserSaving.value = true;
    createUserError.value = '';

    try {
        const { data } = await axios.post('/api/admin/users', {
            ...createUserForm.value,
            department: createUserForm.value.department.trim() || null,
            title: createUserForm.value.title.trim() || null,
            ...accessProfilePayload(createUserForm.value),
        });

        // Hold the dialog open on the credentials panel: closing it loses the
        // only copy of the temporary password.
        createdUserCredentials.value = {
            name: data.data.name,
            email: data.data.email,
            password: data.temporary_password,
            nextSteps: data.next_steps,
        };
        await loadAdminUsers(1);
    } catch (error) {
        const errors = error.response?.data?.errors;
        createUserError.value = errors
            ? Object.values(errors).flat()[0]
            : error.response?.data?.message ?? 'The account could not be created.';
    } finally {
        createUserSaving.value = false;
    }
}

async function copyTemporaryPassword() {
    await navigator.clipboard?.writeText(createdUserCredentials.value?.password ?? '');
}

function closeCreateUser() {
    createUserDialogOpen.value = false;
    createdUserCredentials.value = null;
}
const auditTrail = ref({
    data: [],
    event_types: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
});
const auditLoading = ref(false);
const auditError = ref('');
const auditFilters = ref({ event: '', date_from: '', date_to: '' });
const sourceDialogOpen = ref(false);
const sourceSaving = ref(false);
const testingSourceId = ref(null);
const previewingSourceId = ref(null);
const editingSourceId = ref(null);
const sourceForm = ref(emptySourceForm());
const previewDialogOpen = ref(false);
const searchConsolePreview = ref(null);
const previewError = ref('');
const previewFilters = ref({ date_from: '', date_to: '', dimension: 'query', limit: 25 });
const aiStatus = ref({ configured: false, provider: 'openai', model: '', tools: [], sources: [] });
const conversations = ref([]);
const activeConversation = ref(null);
const chatMessages = ref([]);
const chatInput = ref('');
const aiLoading = ref(false);
const chatSending = ref(false);
const copiedMessageId = ref(null);
const aiError = ref('');
const dashboards = ref([]);
const activeDashboard = ref(null);
const dashboardLoading = ref(false);
const dashboardError = ref('');
const dashboardSearchConsoleSources = ref([]);
const dashboardSearchConsole = ref(null);
const dashboardSearchConsoleLoading = ref(false);
const dashboardSearchConsoleError = ref('');
const dashboardSearchConsoleFilters = ref({
    data_source_id: null,
    date_from: '',
    date_to: '',
    dimension: 'query',
    limit: 25,
});
const freshserviceSources = ref([]);
const freshserviceAnalytics = ref(null);
const freshserviceLoading = ref(false);
const freshserviceError = ref('');
const freshserviceSourceId = ref(null);
const slaCategoryFilters = ref({ category: null, sub_category: null, item: null });
const slaCategoryLevel = ref('category');
const security = ref(null);
const securityLoading = ref(false);
const securityScanning = ref(false);
const securityError = ref('');
const securityNotice = ref('');
const securityTrendDays = ref(30);
const securitySection = ref('overview');
const securityEventDialogOpen = ref(false);
const securityEventSaving = ref(false);
const activeSecurityEvent = ref(null);
const securityEventForm = ref({ status: 'acknowledged', resolution_note: '' });
const reports = ref({ data: [], sources: [], types: [] });
const activeReport = ref(null);
const reportLoading = ref(false);
const reportError = ref('');
const reportDialogOpen = ref(false);
const reportSaving = ref(false);
const editingReportId = ref(null);
const reportFilters = ref({ date_from: '', date_to: '', region: '', status: '' });
const reportForm = ref(emptyReportForm());
const schedules = ref({ data: [], reports: [], timezones: [], teams_configured: false });
const schedulesLoading = ref(false);
const scheduleError = ref('');
const scheduleDialogOpen = ref(false);
const scheduleSaving = ref(false);
const runningScheduleId = ref(null);
const editingScheduleId = ref(null);
const scheduleForm = ref(emptyScheduleForm());
const analytics = ref({
    summary: { total: 0, anomalies: 0, forecasts: 0, actions: 0 },
    data: [],
    reports: [],
});
const analyticsLoading = ref(false);
const analyticsError = ref('');
const analyticsReportId = ref(null);
const generatingAnalyticsId = ref(null);
const credentials = ref({
    email: '',
    password: '',
    remember: false,
});

/* ---------------------------------------------------------------
 * Router bridge (temporary, removed with this file)
 * ------------------------------------------------------------- */

const legacyRoute = useRoute();
const legacyRouter = useRouter();

/** Views that now live in their own page component. */
const MIGRATED_VIEWS = { security: 'security' };

// Keep the internal switcher aligned with the URL so browser back/forward and
// deep links work for legacy views, AND load that view's data. The watch is the
// only entry point for router-driven navigation, so omitting the load leaves the
// view rendering its initial empty state.
watch(
    () => legacyRoute.name,
    async (name) => {
        if (! name || MIGRATED_VIEWS[name] || name === currentView.value) {
            return;
        }

        currentView.value = name;
        await loadViewData(name);
    },
);

const currentDateTime = ref(new Date());
let clockIntervalId = null;

const greeting = computed(() => {
    const hour = currentDateTime.value.getHours();

    if (hour < 12) {
        return 'Good morning';
    }

    if (hour < 18) {
        return 'Good afternoon';
    }

    return 'Good evening';
});

const overviewDate = computed(() => new Intl.DateTimeFormat(undefined, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
}).format(currentDateTime.value));

/*
 * Navigation lists only what the user may actually open.
 *
 * These entries were previously rendered for everyone and merely disabled,
 * which advertised capabilities the user could not use and disclosed the
 * platform capability map to every account. Filtering here rather than in the
 * template means no hidden entry reaches the DOM at all, and an empty group
 * hides its own heading. This is presentation only — every route behind these
 * entries stays permission-checked on the server.
 */
const navItems = computed(() => {
    const permissions = platform.value?.user?.permissions ?? [];

    return [
        { id: 'overview', label: 'Overview', icon: 'pi-home', available: true },
        { id: 'ai', label: 'Ask GAHolding', icon: 'pi-sparkles', available: permissions.includes('ai.chat') },
        { id: 'dashboards', label: 'Dashboards', icon: 'pi-chart-bar', available: permissions.includes('dashboards.view') },
        { id: 'reports', label: 'Reports', icon: 'pi-file', available: permissions.includes('reports.view') },
        { id: 'schedules', label: 'Schedules', icon: 'pi-clock', available: permissions.includes('reports.schedule') },
        { id: 'analytics', label: 'Advanced analytics', icon: 'pi-chart-line', available: permissions.includes('analytics.view') },
    ].filter((item) => item.available);
});

const currentViewLabel = computed(() => ({
    integrations: 'Data sources',
    users: 'Users & access',
    audit: 'Audit trail',
    security: 'Security',
    ai: 'Ask GAHolding',
    dashboards: 'Dashboards',
    reports: 'Reports',
    schedules: 'Schedules',
    analytics: 'Advanced analytics',
}[currentView.value] ?? 'Overview'));

function emptyScheduleForm() {
    const today = new Date();
    const oneMonthAgo = new Date(today.getFullYear(), today.getMonth() - 1, today.getDate());

    return {
        report_id: null,
        frequency: 'daily',
        cron_expression: '0 8 * * *',
        timezone: 'Asia/Dubai',
        format: 'pdf',
        filters: {
            date_from: oneMonthAgo.toISOString().split('T')[0],
            date_to: today.toISOString().split('T')[0],
            region: '',
            status: '',
        },
        delivery_channels: ['email'],
        recipients_text: '',
        is_active: true,
    };
}

function emptyReportForm() {
    return {
        name: '',
        type: 'custom',
        description: '',
        visibility: 'private',
        definition: {
            source_id: null,
            columns: [
                { key: 'label', label: 'Label', type: 'text' },
                { key: 'value', label: 'Value', type: 'number' },
            ],
            chart: { type: 'bar', title: 'Report view', category_key: 'label', value_key: 'value' },
            filters: ['date_from', 'date_to', 'department', 'region', 'status'],
            search_console_dimension: 'query',
        },
    };
}

const selectedReportSource = computed(() => reports.value.sources.find(
    (source) => source.id === reportForm.value.definition.source_id,
));
const selectedDashboardSearchConsoleSource = computed(() => dashboardSearchConsoleSources.value.find(
    (source) => source.id === dashboardSearchConsoleFilters.value.data_source_id,
));
/*
 * Platforms selected for this user that authorize nobody, so the selection is
 * inert. `authorizes_anyone` is false when the data source names neither a role
 * nor a department: the per-user list narrows the audience a source defines
 * rather than granting access, so such a selection silently does nothing.
 */
const inertSelectedPlatforms = computed(() => {
    if (!userAccessForm.value.restrict_data_sources) {
        return [];
    }

    return (adminUsers.value.data_sources ?? []).filter((source) => (
        source.authorizes_anyone === false
        && (userAccessForm.value.allowed_data_source_ids ?? []).includes(source.id)
    ));
});
const activeDashboardSupportsSearchConsole = computed(() => (
    activeDashboard.value?.reports?.some((report) => report.type === 'website_analytics') ?? false
));
const activeDashboardSupportsFreshservice = computed(() => activeDashboard.value?.slug === 'itsm');
const selectedFreshserviceSource = computed(() => freshserviceSources.value.find(
    (source) => source.id === freshserviceSourceId.value,
));

const adminItems = computed(() => {
    const permissions = platform.value?.user?.permissions ?? [];

    return [
        { id: 'integrations', label: 'Data sources', icon: 'pi-database', available: permissions.includes('integrations.manage') },
        { id: 'users', label: 'Users & access', icon: 'pi-users', available: permissions.includes('users.view') },
        { id: 'security', label: 'Security', icon: 'pi-lock', available: permissions.includes('security.view') },
        { id: 'audit', label: 'Audit trail', icon: 'pi-shield', available: permissions.includes('audit.view') },
    ].filter((item) => item.available);
});

function emptySourceForm() {
    return {
        name: '',
        type: 'crm',
        description: '',
        base_url: '',
        auth_type: 'none',
        credentials: {
            token: '',
            api_key: '',
            header: 'X-API-Key',
            username: '',
            password: '',
        },
        settings: {
            health_path: '/health',
            data_path: '',
            site_url: '',
            allowed_roles: [],
        },
        allowed_departments_text: '',
        on_hold_status_ids_text: '3',
        timeout_seconds: 30,
        retry_count: 2,
    };
}

function applySourceType() {
    if (sourceForm.value.type === 'freshservice') {
        sourceForm.value.auth_type = 'basic';
        sourceForm.value.settings.health_path = '/api/v2/tickets';
        sourceForm.value.settings.data_path = '/api/v2/tickets';
        sourceForm.value.credentials.password = 'X';
        sourceForm.value.on_hold_status_ids_text = '3';

        return;
    }

    if (sourceForm.value.type !== 'google_search_console') {
        return;
    }

    sourceForm.value.base_url = integrations.value.search_console?.api_url
        ?? 'https://www.googleapis.com/webmasters/v3';
    sourceForm.value.auth_type = 'none';
    sourceForm.value.settings.health_path = '';
    sourceForm.value.settings.data_path = '';
    sourceForm.value.settings.site_url = integrations.value.search_console?.site_url ?? '';
}

const chartOptions = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
    colors: ['#36c8b5'],
    dataLabels: { enabled: false },
    grid: { borderColor: '#e8eceb', strokeDashArray: 4 },
    plotOptions: { bar: { borderRadius: 7, columnWidth: '42%' } },
    xaxis: {
        categories: platform.value?.phases.map((phase) => phase.name) ?? [],
        labels: { style: { colors: '#73807c', fontSize: '12px' } },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: {
        max: 100,
        labels: { formatter: (value) => `${value}%`, style: { colors: '#73807c' } },
    },
    tooltip: { y: { formatter: (value) => `${value}% complete` } },
}));

const chartSeries = computed(() => [{
    name: 'Completion',
    data: platform.value?.phases.map((phase) => phase.progress) ?? [0, 0, 0, 0],
}]);

async function loadPlatform() {
    try {
        const { data } = await axios.get('/api/bootstrap');
        platform.value = data;
    } catch (error) {
        if (error.response?.status !== 401) {
            loginError.value = 'The platform could not be loaded. Please try again.';
        }
    } finally {
        loading.value = false;
    }
}

async function login() {
    submitting.value = true;
    loginError.value = '';

    try {
        await axios.post('/auth/login', credentials.value);
        await loadPlatform();
        await applyDeepLink();
    } catch (error) {
        loginError.value = error.response?.data?.message
            ?? error.response?.data?.errors?.email?.[0]
            ?? 'Sign-in failed. Check your credentials.';
    } finally {
        submitting.value = false;
    }
}

async function logout() {
    await axios.post('/auth/logout');
    platform.value = null;
}

/**
 * Fetch the data a legacy view needs.
 *
 * Kept separate from setView because navigation now arrives from the router as
 * well as from in-page calls, and both paths must load data. When this was
 * inlined in setView, router-driven navigation rendered every view with its
 * initial empty state.
 */
async function loadViewData(view) {
    const loaders = {
        integrations: loadIntegrations,
        users: loadAdminUsers,
        audit: loadAuditTrail,
        ai: loadAiWorkspace,
        dashboards: loadDashboards,
        reports: loadReports,
        schedules: loadSchedules,
        analytics: loadAnalytics,
    };

    await loaders[view]?.();
}

async function setView(view) {
    // Migrated views are owned by the router now.
    if (MIGRATED_VIEWS[view]) {
        sidebarOpen.value = false;
        await legacyRouter.push({ name: MIGRATED_VIEWS[view] });

        return;
    }

    currentView.value = view;
    sidebarOpen.value = false;

    // Keep the address bar truthful for legacy views.
    if (legacyRoute.name !== view) {
        legacyRouter.replace({ name: view }).catch(() => {});
    }

    await loadViewData(view);
}

async function loadIntegrations() {
    integrationsLoading.value = true;
    integrationError.value = '';

    try {
        const { data } = await axios.get('/api/integrations');
        integrations.value = data;
    } catch (error) {
        integrationError.value = error.response?.status === 403
            ? 'Your account does not have permission to manage integrations.'
            : 'Integration records could not be loaded.';
    } finally {
        integrationsLoading.value = false;
    }
}

async function loadAdminUsers(page = 1) {
    adminUsersLoading.value = true;
    adminUsersError.value = '';

    try {
        const { data } = await axios.get('/api/admin/users', {
            params: {
                page,
                search: adminUserSearch.value.trim() || undefined,
            },
        });
        adminUsers.value = data;
    } catch (error) {
        adminUsersError.value = error.response?.status === 403
            ? 'Your account does not have permission to view users.'
            : 'Users and access settings could not be loaded.';
    } finally {
        adminUsersLoading.value = false;
    }
}

function openUserAccess(user) {
    editingUserId.value = user.id;
    userAccessForm.value = {
        name: user.name,
        email: user.email,
        department: user.department ?? '',
        title: user.title ?? '',
        is_active: user.is_active,
        roles: user.roles.map((role) => role.name),
        allowed_departments: [...(user.allowed_departments ?? [])],
        restrict_data_sources: Array.isArray(user.allowed_data_source_ids),
        allowed_data_source_ids: [...(user.allowed_data_source_ids ?? [])],
    };
    adminUsersError.value = '';
    userAccessDialogOpen.value = true;
}

/** Shapes the access profile the way the API distinguishes its three states. */
function accessProfilePayload(form) {
    return {
        allowed_departments: form.allowed_departments ?? [],
        allowed_data_source_ids: form.restrict_data_sources
            ? form.allowed_data_source_ids ?? []
            : null,
    };
}

async function saveUserAccess() {
    userAccessSaving.value = true;
    adminUsersError.value = '';

    try {
        await axios.put(`/api/admin/users/${editingUserId.value}`, {
            ...userAccessForm.value,
            department: userAccessForm.value.department.trim() || null,
            title: userAccessForm.value.title.trim() || null,
            ...accessProfilePayload(userAccessForm.value),
        });
        userAccessDialogOpen.value = false;
        await loadAdminUsers(adminUsers.value.meta.current_page);

        if (editingUserId.value === platform.value.user.id) {
            await loadPlatform();
        }
    } catch (error) {
        adminUsersError.value = Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? error.response?.data?.message
            ?? 'The user access settings could not be saved.';
    } finally {
        userAccessSaving.value = false;
    }
}

async function loadAuditTrail(page = 1) {
    auditLoading.value = true;
    auditError.value = '';

    try {
        const { data } = await axios.get('/api/admin/audit', {
            params: {
                page,
                ...Object.fromEntries(
                    Object.entries(auditFilters.value).filter(([, value]) => value !== ''),
                ),
            },
        });
        auditTrail.value = data;
    } catch (error) {
        auditError.value = error.response?.status === 403
            ? 'Your account does not have permission to view the audit trail.'
            : 'Audit events could not be loaded.';
    } finally {
        auditLoading.value = false;
    }
}

function openCreateSource() {
    editingSourceId.value = null;
    sourceForm.value = emptySourceForm();
    integrationError.value = '';
    sourceDialogOpen.value = true;
}

function openEditSource(source) {
    const isSearchConsole = source.type === 'google_search_console';

    editingSourceId.value = source.id;
    sourceForm.value = {
        name: source.name,
        type: source.type,
        description: source.description ?? '',
        base_url: source.base_url,
        auth_type: source.auth_type,
        credentials: {
            token: '',
            api_key: '',
            header: 'X-API-Key',
            username: '',
            password: '',
        },
        settings: {
            health_path: isSearchConsole ? '' : source.settings?.health_path ?? '/health',
            data_path: isSearchConsole ? '' : source.settings?.data_path ?? '',
            site_url: source.settings?.site_url ?? integrations.value.search_console?.site_url ?? '',
            allowed_roles: source.settings?.allowed_roles ?? [],
        },
        allowed_departments_text: (source.settings?.allowed_departments ?? []).join(', '),
        on_hold_status_ids_text: (source.settings?.on_hold_status_ids ?? [3]).join(', '),
        timeout_seconds: source.timeout_seconds,
        retry_count: source.retry_count,
    };
    integrationError.value = '';
    sourceDialogOpen.value = true;
}

function credentialPayload() {
    const form = sourceForm.value;

    if (form.auth_type === 'bearer' && form.credentials.token) {
        return { token: form.credentials.token };
    }

    if (form.auth_type === 'api_key' && form.credentials.api_key) {
        return {
            api_key: form.credentials.api_key,
            header: form.credentials.header || 'X-API-Key',
        };
    }

    if (form.auth_type === 'basic' && (form.credentials.username || form.credentials.password)) {
        return {
            username: form.credentials.username,
            password: form.credentials.password,
        };
    }

    return form.auth_type === 'none' ? {} : null;
}

async function saveSource() {
    sourceSaving.value = true;
    integrationError.value = '';

    const credentialsPayload = credentialPayload();
    const settings = {
        ...sourceForm.value.settings,
        allowed_departments: sourceForm.value.allowed_departments_text
            .split(',')
            .map((department) => department.trim())
            .filter(Boolean),
    };

    if (sourceForm.value.type === 'google_search_console') {
        delete settings.health_path;
        delete settings.data_path;
    }

    if (sourceForm.value.type === 'freshservice') {
        settings.on_hold_status_ids = sourceForm.value.on_hold_status_ids_text
            .split(',')
            .map((status) => Number(status.trim()))
            .filter((status) => Number.isInteger(status) && status > 0);
    } else {
        delete settings.on_hold_status_ids;
    }

    const payload = {
        name: sourceForm.value.name,
        type: sourceForm.value.type,
        description: sourceForm.value.description || null,
        base_url: sourceForm.value.base_url,
        auth_type: sourceForm.value.auth_type,
        settings,
        timeout_seconds: sourceForm.value.timeout_seconds,
        retry_count: sourceForm.value.retry_count,
    };

    if (credentialsPayload !== null) {
        payload.credentials = credentialsPayload;
    }

    try {
        if (editingSourceId.value) {
            await axios.put(`/api/integrations/${editingSourceId.value}`, payload);
        } else {
            await axios.post('/api/integrations', payload);
        }

        sourceDialogOpen.value = false;
        await loadIntegrations();
        await loadPlatform();
    } catch (error) {
        const errors = error.response?.data?.errors;
        integrationError.value = errors
            ? Object.values(errors).flat()[0]
            : error.response?.data?.message ?? 'The data source could not be saved.';
    } finally {
        sourceSaving.value = false;
    }
}

async function testSource(source) {
    testingSourceId.value = source.id;
    integrationError.value = '';

    try {
        await axios.post(`/api/integrations/${source.id}/test`);
    } catch (error) {
        integrationError.value = error.response?.data?.result?.message
            ?? 'The connection test did not succeed.';
    } finally {
        await loadIntegrations();
        testingSourceId.value = null;
    }
}

async function previewSource(source) {
    previewingSourceId.value = source.id;
    previewError.value = '';

    try {
        const { data } = await axios.get(`/api/integrations/${source.id}/preview`, {
            params: Object.fromEntries(
                Object.entries(previewFilters.value).filter(([, value]) => value !== ''),
            ),
        });
        searchConsolePreview.value = data.data;
        previewDialogOpen.value = true;
    } catch (error) {
        previewError.value = error.response?.data?.message
            ?? Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? 'Search Console data could not be loaded.';
        integrationError.value = previewError.value;
    } finally {
        previewingSourceId.value = null;
    }
}

async function removeSource(source) {
    if (!window.confirm(`Remove ${source.name}? Its connection history will also be removed.`)) {
        return;
    }

    await axios.delete(`/api/integrations/${source.id}`);
    await loadIntegrations();
    await loadPlatform();
}

function statusSeverity(status) {
    return {
        connected: 'success',
        error: 'danger',
        testing: 'warn',
        draft: 'secondary',
    }[status] ?? 'info';
}

async function loadAiWorkspace() {
    aiLoading.value = true;
    aiError.value = '';

    try {
        const [statusResponse, conversationsResponse] = await Promise.all([
            axios.get('/api/ai/status'),
            axios.get('/api/ai/conversations'),
        ]);
        aiStatus.value = statusResponse.data;
        conversations.value = conversationsResponse.data.data;
    } catch (error) {
        aiError.value = error.response?.status === 403
            ? 'Your account is not authorized to use AI reporting.'
            : 'The AI reporting workspace could not be loaded.';
    } finally {
        aiLoading.value = false;
    }
}

async function openConversation(conversation) {
    aiLoading.value = true;
    aiError.value = '';

    try {
        const { data } = await axios.get(`/api/ai/conversations/${conversation.id}`);
        activeConversation.value = data.conversation;
        chatMessages.value = data.messages;
    } catch (error) {
        aiError.value = 'The conversation could not be loaded.';
    } finally {
        aiLoading.value = false;
    }
}

function startNewConversation(prompt = '') {
    activeConversation.value = null;
    chatMessages.value = [];
    chatInput.value = prompt;
    aiError.value = '';
}

async function sendChat() {
    const content = chatInput.value.trim();

    if (!content || chatSending.value || !aiStatus.value.configured) {
        return;
    }

    chatMessages.value.push({
        id: `local-${Date.now()}`,
        role: 'user',
        content,
        citations: [],
        tool_calls: [],
    });
    chatInput.value = '';
    chatSending.value = true;
    aiError.value = '';

    try {
        const { data } = await axios.post('/api/ai/chat', {
            conversation_id: activeConversation.value?.id ?? null,
            content,
        });
        activeConversation.value = data.conversation;
        chatMessages.value.push(data.message);
        await refreshConversations();
    } catch (error) {
        aiError.value = error.response?.data?.message ?? 'Ask GAHolding could not complete this report request.';
    } finally {
        chatSending.value = false;
    }
}

async function refreshConversations() {
    const { data } = await axios.get('/api/ai/conversations');
    conversations.value = data.data;
}

/**
 * An answer's usual next stop is a mail or a deck, so offer the raw Markdown
 * rather than making the reader drag-select a rendered card.
 */
async function copyAnswer(message) {
    try {
        await navigator.clipboard.writeText(message.content);
        copiedMessageId.value = message.id;
        window.setTimeout(() => {
            if (copiedMessageId.value === message.id) copiedMessageId.value = null;
        }, 2000);
    } catch (error) {
        aiError.value = 'The answer could not be copied to the clipboard.';
    }
}

async function removeConversation(conversation) {
    if (!window.confirm(`Delete "${conversation.title}"?`)) {
        return;
    }

    await axios.delete(`/api/ai/conversations/${conversation.id}`);

    if (activeConversation.value?.id === conversation.id) {
        startNewConversation();
    }

    await refreshConversations();
}

function useSuggestedPrompt(prompt) {
    startNewConversation(prompt);
}

async function loadDashboards(slug = null) {
    dashboardLoading.value = true;
    dashboardError.value = '';

    try {
        const { data } = await axios.get('/api/dashboards');
        dashboards.value = data.data;
        dashboardSearchConsoleSources.value = data.search_console_sources ?? [];
        freshserviceSources.value = data.freshservice_sources ?? [];

        if (!dashboardSearchConsoleFilters.value.data_source_id && dashboardSearchConsoleSources.value.length) {
            dashboardSearchConsoleFilters.value.data_source_id = dashboardSearchConsoleSources.value[0].id;
        }
        if (!freshserviceSourceId.value && freshserviceSources.value.length) {
            freshserviceSourceId.value = freshserviceSources.value[0].id;
        }

        const selectedSlug = slug ?? activeDashboard.value?.slug ?? dashboards.value[0]?.slug;

        if (selectedSlug) {
            const response = await axios.get(`/api/dashboards/${selectedSlug}`, { params: cleanFilters() });
            activeDashboard.value = response.data.data;
        }

        if (activeDashboardSupportsSearchConsole.value
            && selectedDashboardSearchConsoleSource.value?.status === 'connected') {
            await loadDashboardSearchConsole();
        } else {
            dashboardSearchConsole.value = null;
            dashboardSearchConsoleError.value = '';
        }

        if (activeDashboardSupportsFreshservice.value && selectedFreshserviceSource.value?.status === 'connected') {
            await loadFreshserviceAnalytics();
        } else {
            freshserviceAnalytics.value = null;
            freshserviceError.value = '';
        }
    } catch (error) {
        dashboardError.value = error.response?.status === 403
            ? 'Your account is not authorized to view dashboards.'
            : 'Dashboard data could not be loaded.';
    } finally {
        dashboardLoading.value = false;
    }
}

async function loadFreshserviceAnalytics() {
    if (!selectedFreshserviceSource.value || selectedFreshserviceSource.value.status !== 'connected') {
        freshserviceError.value = 'An administrator must successfully test the Freshservice source first.';
        freshserviceAnalytics.value = null;

        return;
    }

    freshserviceLoading.value = true;
    freshserviceError.value = '';

    try {
        const { data } = await axios.get('/api/dashboards/freshservice', {
            params: {
                data_source_id: freshserviceSourceId.value,
                date_from: reportFilters.value.date_from || undefined,
                date_to: reportFilters.value.date_to || undefined,
            },
        });
        freshserviceAnalytics.value = data.data;
        resetSlaCategoryFilters();
    } catch (error) {
        freshserviceError.value = error.response?.data?.message
            ?? Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? 'Freshservice ticket analytics could not be loaded.';
    } finally {
        freshserviceLoading.value = false;
    }
}

async function loadDashboardSearchConsole() {
    const source = dashboardSearchConsoleSources.value.find(
        (item) => item.id === dashboardSearchConsoleFilters.value.data_source_id,
    );

    if (!source || source.status !== 'connected') {
        dashboardSearchConsoleError.value = 'An administrator must successfully test this Search Console source first.';
        dashboardSearchConsole.value = null;

        return;
    }

    dashboardSearchConsoleLoading.value = true;
    dashboardSearchConsoleError.value = '';

    try {
        const { data } = await axios.get('/api/dashboards/search-console', {
            params: Object.fromEntries(
                Object.entries(dashboardSearchConsoleFilters.value).filter(([, value]) => value !== ''),
            ),
        });
        dashboardSearchConsole.value = data.data;
    } catch (error) {
        dashboardSearchConsoleError.value = error.response?.data?.message
            ?? Object.values(error.response?.data?.errors ?? {}).flat()[0]
            ?? 'Search Console dashboard data could not be loaded.';
    } finally {
        dashboardSearchConsoleLoading.value = false;
    }
}

async function loadReports(selectId = null) {
    reportLoading.value = true;
    reportError.value = '';

    try {
        const { data } = await axios.get('/api/reports');
        reports.value = data;
        const reportId = selectId ?? activeReport.value?.id ?? data.data[0]?.id;

        if (reportId) {
            await openReport(reportId);
        }
    } catch (error) {
        reportError.value = error.response?.status === 403
            ? 'Your account is not authorized to view reports.'
            : 'Reports could not be loaded.';
    } finally {
        reportLoading.value = false;
    }
}

async function openReport(reportId) {
    reportLoading.value = true;
    reportError.value = '';

    try {
        const { data } = await axios.get(`/api/reports/${reportId}`, { params: cleanFilters() });
        activeReport.value = data.data;
    } catch (error) {
        reportError.value = error.response?.data?.message ?? 'The report could not be loaded.';
    } finally {
        reportLoading.value = false;
    }
}

function cleanFilters() {
    return Object.fromEntries(Object.entries(reportFilters.value).filter(([, value]) => value));
}

function openCreateReport() {
    editingReportId.value = null;
    reportForm.value = emptyReportForm();
    reportDialogOpen.value = true;
    reportError.value = '';
}

function openEditReport(report) {
    editingReportId.value = report.id;
    reportForm.value = JSON.parse(JSON.stringify({
        name: report.name,
        type: report.type,
        description: report.description ?? '',
        visibility: report.visibility,
        definition: report.definition,
    }));
    reportDialogOpen.value = true;
}

function applyReportSource() {
    if (selectedReportSource.value?.type === 'freshservice') {
        reportForm.value.type = 'itsm_ticket_summary';
        reportForm.value.definition.columns = [
            { key: 'section', label: 'Section', type: 'text' },
            { key: 'metric', label: 'Metric', type: 'text' },
            { key: 'detail', label: 'Detail', type: 'text' },
            { key: 'count', label: 'Ticket count', type: 'number' },
        ];
        reportForm.value.definition.chart = {
            type: 'bar',
            title: 'Freshservice ticket summary',
            category_key: 'metric',
            value_key: 'count',
        };

        return;
    }

    if (selectedReportSource.value?.type !== 'google_search_console') {
        return;
    }

    reportForm.value.type = 'website_analytics';
    reportForm.value.definition.search_console_dimension ??= 'query';
    applySearchConsoleReportDimension();
}

function applySearchConsoleReportDimension() {
    const dimension = reportForm.value.definition.search_console_dimension ?? 'query';
    const labels = {
        query: 'Query',
        page: 'Page',
        country: 'Country',
        device: 'Device',
        date: 'Date',
    };

    reportForm.value.definition.columns = [
        { key: dimension, label: labels[dimension], type: dimension === 'date' ? 'date' : 'text' },
        { key: 'clicks', label: 'Clicks', type: 'number' },
        { key: 'impressions', label: 'Impressions', type: 'number' },
        { key: 'ctr', label: 'CTR', type: 'percentage' },
        { key: 'position', label: 'Average position', type: 'number' },
    ];
    reportForm.value.definition.chart = {
        type: dimension === 'date' ? 'line' : 'bar',
        title: `Google Search clicks by ${labels[dimension].toLowerCase()}`,
        category_key: dimension,
        value_key: 'clicks',
    };
}

function searchConsoleChartOptions() {
    const data = dashboardSearchConsole.value;
    const dimension = data?.summary?.dimension ?? 'query';

    return {
        chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
        colors: ['#19a7a0'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 3 },
        xaxis: {
            categories: data?.rows?.map((row) => String(row[dimension] ?? 'Unknown')) ?? [],
            labels: { rotate: -35, trim: true },
        },
        yaxis: { labels: { formatter: (value) => Math.round(value).toLocaleString() } },
        tooltip: { y: { formatter: (value) => `${Number(value).toLocaleString()} clicks` } },
        noData: { text: 'Select a connected Search Console source.' },
    };
}

function searchConsoleChartSeries() {
    return [{
        name: 'Clicks',
        data: dashboardSearchConsole.value?.rows?.map((row) => Number(row.clicks ?? 0)) ?? [],
    }];
}

function itsmPieOptions(items) {
    return {
        chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
        colors: ['#19a7a0', '#143d3a', '#f2aa4c', '#657b76', '#d05c5c', '#78c6b8'],
        labels: items?.map((item) => item.label) ?? [],
        legend: { position: 'bottom', fontSize: '11px' },
        dataLabels: { enabled: true },
        stroke: { colors: ['#fff'] },
        noData: { text: 'No ticket data available.' },
    };
}

function itsmPieSeries(items) {
    return items?.map((item) => Number(item.value ?? 0)) ?? [];
}

function itsmBarOptions(items, labelKey = 'label') {
    return {
        chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
        colors: ['#19a7a0'],
        dataLabels: { enabled: false },
        plotOptions: { bar: { horizontal: true, borderRadius: 5 } },
        xaxis: {
            categories: items?.map((item) => item[labelKey]) ?? [],
            labels: { formatter: (value) => Math.round(value).toLocaleString() },
        },
        tooltip: { y: { formatter: (value) => `${Number(value).toLocaleString()} tickets` } },
        noData: { text: 'No ticket data available.' },
    };
}

function itsmBarSeries(items, name) {
    return [{
        name,
        data: items?.map((item) => Number(item.value ?? 0)) ?? [],
    }];
}

function slaGroupAgentRows() {
    return freshserviceAnalytics.value?.sla_breached_by_group_agent?.map((item) => ({
        ...item,
        label: `${item.group} · ${item.agent}`,
    })) ?? [];
}

/* ------------------------------------------------------------------
 * SLA by Category -> Sub Category -> Item
 * ------------------------------------------------------------------ */

const slaCategoryFlat = computed(() => freshserviceAnalytics.value?.sla_by_category?.flat ?? []);

const slaCategoryScoped = computed(() => {
    const { category, sub_category: subCategory, item } = slaCategoryFilters.value;

    return slaCategoryFlat.value.filter((row) => (
        (!category || row.category === category)
        && (!subCategory || row.sub_category === subCategory)
        && (!item || row.item === item)
    ));
});

const slaCategoryOptions = computed(() => {
    const unique = (values) => [...new Set(values)].sort((a, b) => a.localeCompare(b));

    return unique(slaCategoryFlat.value.map((row) => row.category))
        .map((value) => ({ label: value, value }));
});

const slaSubCategoryOptions = computed(() => {
    const { category } = slaCategoryFilters.value;
    const scoped = category
        ? slaCategoryFlat.value.filter((row) => row.category === category)
        : slaCategoryFlat.value;
    const unique = [...new Set(scoped.map((row) => row.sub_category))]
        .sort((a, b) => a.localeCompare(b));

    return unique.map((value) => ({ label: value, value }));
});

const slaItemOptions = computed(() => {
    const { category, sub_category: subCategory } = slaCategoryFilters.value;
    const scoped = slaCategoryFlat.value.filter((row) => (
        (!category || row.category === category)
        && (!subCategory || row.sub_category === subCategory)
    ));
    const unique = [...new Set(scoped.map((row) => row.item))]
        .sort((a, b) => a.localeCompare(b));

    return unique.map((value) => ({ label: value, value }));
});

const slaCategoryLevelOptions = [
    { label: 'Category', value: 'category' },
    { label: 'Sub Category', value: 'sub_category' },
    { label: 'Item', value: 'item' },
];

/** Aggregate the scoped rows at the selected hierarchy level. */
const slaCategoryChartRows = computed(() => {
    const level = slaCategoryLevel.value;
    const buckets = new Map();

    slaCategoryScoped.value.forEach((row) => {
        let label;

        if (level === 'category') {
            label = row.category;
        } else if (level === 'sub_category') {
            label = slaCategoryFilters.value.category
                ? row.sub_category
                : `${row.category} · ${row.sub_category}`;
        } else {
            label = slaCategoryFilters.value.sub_category
                ? row.item
                : `${row.sub_category} · ${row.item}`;
        }

        const bucket = buckets.get(label) ?? { label, breached: 0, total: 0 };
        bucket.breached += Number(row.breached ?? 0);
        bucket.total += Number(row.total ?? 0);
        buckets.set(label, bucket);
    });

    return [...buckets.values()]
        .map((bucket) => ({
            ...bucket,
            compliant: bucket.total - bucket.breached,
            compliance: bucket.total > 0
                ? Math.round(((bucket.total - bucket.breached) / bucket.total) * 1000) / 10
                : 100,
        }))
        .sort((a, b) => b.breached - a.breached || b.total - a.total)
        .slice(0, 25);
});

const slaCategoryTotals = computed(() => {
    const rows = slaCategoryScoped.value;
    const total = rows.reduce((sum, row) => sum + Number(row.total ?? 0), 0);
    const breached = rows.reduce((sum, row) => sum + Number(row.breached ?? 0), 0);

    return {
        total,
        breached,
        compliant: total - breached,
        compliance: total > 0 ? Math.round(((total - breached) / total) * 1000) / 10 : 100,
    };
});

const slaCategoryChartOptions = computed(() => {
    const rows = slaCategoryChartRows.value;

    return {
        chart: {
            type: 'bar',
            stacked: true,
            toolbar: { show: false },
            fontFamily: 'Inter, sans-serif',
        },
        colors: ['#d05c5c', '#19a7a0'],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '70%' } },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px' },
        xaxis: {
            categories: rows.map((row) => row.label),
            title: { text: 'Unresolved tickets', style: { fontSize: '11px', fontWeight: 500 } },
            labels: { formatter: (value) => Math.round(value).toLocaleString() },
        },
        yaxis: { labels: { maxWidth: 260, style: { fontSize: '11px' } } },
        tooltip: {
            shared: true,
            intersect: false,
            y: { formatter: (value) => `${Number(value).toLocaleString()} tickets` },
            custom: undefined,
        },
        annotations: {},
        noData: { text: 'No ticket data for the selected filters.' },
    };
});

const slaCategoryChartSeries = computed(() => {
    const rows = slaCategoryChartRows.value;

    return [
        { name: 'SLA breached', data: rows.map((row) => row.breached) },
        { name: 'Within SLA', data: rows.map((row) => row.compliant) },
    ];
});

function resetSlaCategoryFilters() {
    slaCategoryFilters.value = { category: null, sub_category: null, item: null };
    slaCategoryLevel.value = 'category';
}

function onSlaCategoryChange() {
    slaCategoryFilters.value.sub_category = null;
    slaCategoryFilters.value.item = null;

    if (slaCategoryFilters.value.category && slaCategoryLevel.value === 'category') {
        slaCategoryLevel.value = 'sub_category';
    }
}

function onSlaSubCategoryChange() {
    slaCategoryFilters.value.item = null;

    if (slaCategoryFilters.value.sub_category && slaCategoryLevel.value !== 'item') {
        slaCategoryLevel.value = 'item';
    }
}

function slaComplianceSeverity(compliance) {
    if (compliance >= 90) return 'success';
    if (compliance >= 75) return 'warn';

    return 'danger';
}

/* ------------------------------------------------------------------
 * Backlog ageing (0-7 / 8-14 / 15-30 / 31-60 / 60+ days)
 * ------------------------------------------------------------------ */

const ageingBands = computed(() => freshserviceAnalytics.value?.ageing_bands?.bands ?? []);

const ageingChartOptions = computed(() => ({
    chart: { type: 'bar', stacked: true, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
    colors: ['#d05c5c', '#19a7a0'],
    plotOptions: { bar: { columnWidth: '55%', borderRadius: 5 } },
    dataLabels: { enabled: false },
    legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px' },
    xaxis: { categories: ageingBands.value.map((band) => band.label) },
    yaxis: {
        title: { text: 'Unresolved tickets', style: { fontSize: '11px', fontWeight: 500 } },
        labels: { formatter: (value) => Math.round(value).toLocaleString() },
    },
    tooltip: { shared: true, intersect: false, y: { formatter: (value) => `${Number(value).toLocaleString()} tickets` } },
    noData: { text: 'No ticket data available.' },
}));

const ageingChartSeries = computed(() => [
    { name: 'SLA breached', data: ageingBands.value.map((band) => Number(band.breached ?? 0)) },
    { name: 'Within SLA', data: ageingBands.value.map((band) => Number(band.within_sla ?? 0)) },
]);

const ageingMeta = computed(() => ({
    average: freshserviceAnalytics.value?.ageing_bands?.average_age ?? 0,
    median: freshserviceAnalytics.value?.ageing_bands?.median_age ?? 0,
    oldest: freshserviceAnalytics.value?.ageing_bands?.oldest ?? [],
}));

/* ------------------------------------------------------------------
 * Agent x Status pivot (mirrors "All Unresolved Tickets by Agent")
 * ------------------------------------------------------------------ */

const agentMatrix = computed(() => freshserviceAnalytics.value?.agent_status_matrix
    ?? { columns: [], rows: [], grand_total: 0 });

const agentMatrixRows = computed(() => agentMatrix.value.rows.slice(0, 20));

const agentMatrixChartOptions = computed(() => ({
    chart: { type: 'bar', stacked: true, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
    colors: ['#19a7a0', '#143d3a', '#f2aa4c', '#657b76', '#d05c5c', '#78c6b8', '#9a6419', '#4c7ba8'],
    plotOptions: { bar: { horizontal: true, borderRadius: 3, barHeight: '72%' } },
    dataLabels: { enabled: false },
    legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px' },
    xaxis: {
        categories: agentMatrixRows.value.map((row) => row.agent),
        title: { text: 'Unresolved tickets', style: { fontSize: '11px', fontWeight: 500 } },
        labels: { formatter: (value) => Math.round(value).toLocaleString() },
    },
    yaxis: { labels: { maxWidth: 220, style: { fontSize: '11px' } } },
    tooltip: { shared: true, intersect: false, y: { formatter: (value) => `${Number(value).toLocaleString()} tickets` } },
    noData: { text: 'No unresolved tickets.' },
}));

const agentMatrixChartSeries = computed(() => agentMatrix.value.columns.map((column) => ({
    name: column,
    data: agentMatrixRows.value.map((row) => Number(row.counts?.[column] ?? 0)),
})));

/* ------------------------------------------------------------------
 * Unresolved P1 & P2 tickets
 * ------------------------------------------------------------------ */

const criticalTickets = computed(() => freshserviceAnalytics.value?.critical_tickets ?? []);
const criticalTicketSearch = ref('');

const filteredCriticalTickets = computed(() => {
    const term = criticalTicketSearch.value.trim().toLowerCase();

    if (!term) return criticalTickets.value;

    return criticalTickets.value.filter((ticket) => [
        ticket.id, ticket.group, ticket.agent, ticket.status, ticket.subject, ticket.priority,
    ].some((field) => String(field ?? '').toLowerCase().includes(term)));
});

function prioritySeverity(priority) {
    const value = String(priority ?? '').toLowerCase();

    if (value === 'urgent') return 'danger';
    if (value === 'high') return 'warn';

    return 'secondary';
}

/* ==================================================================
 * Security dashboard
 * ================================================================== */

const securitySections = [
    { id: 'overview', label: 'Overview', icon: 'pi-gauge' },
    { id: 'threats', label: 'Threats', icon: 'pi-exclamation-triangle' },
    { id: 'identity', label: 'Identity & access', icon: 'pi-id-card' },
    { id: 'incidents', label: 'Incidents', icon: 'pi-flag' },
    { id: 'compliance', label: 'Compliance', icon: 'pi-verified' },
    { id: 'assets', label: 'Assets', icon: 'pi-server' },
    { id: 'coverage', label: 'Coverage gaps', icon: 'pi-link' },
];

const securityTrendOptions = [
    { label: 'Last 7 days', value: 7 },
    { label: 'Last 30 days', value: 30 },
    { label: 'Last 90 days', value: 90 },
];

async function loadSecurity() {
    securityLoading.value = true;
    securityError.value = '';

    try {
        const { data } = await axios.get('/api/security', {
            params: { trend_days: securityTrendDays.value },
        });
        security.value = data.data;
    } catch (error) {
        security.value = null;
        securityError.value = error.response?.status === 403
            ? 'Security data is restricted to the IT department and security roles.'
            : error.response?.data?.message ?? 'The security posture could not be loaded.';
    } finally {
        securityLoading.value = false;
    }
}

async function runSecurityScan() {
    securityScanning.value = true;
    securityError.value = '';
    securityNotice.value = '';

    try {
        const { data } = await axios.post('/api/security/scan');
        securityNotice.value = data.message;
        await loadSecurity();
    } catch (error) {
        securityError.value = error.response?.data?.message ?? 'The security scan could not be started.';
    } finally {
        securityScanning.value = false;
    }
}

function openSecurityEvent(event) {
    activeSecurityEvent.value = event;
    securityEventForm.value = {
        status: event.status === 'open' ? 'acknowledged' : event.status,
        resolution_note: '',
    };
    securityEventDialogOpen.value = true;
}

async function saveSecurityEvent() {
    if (!activeSecurityEvent.value) return;

    securityEventSaving.value = true;
    securityError.value = '';

    try {
        await axios.put(`/api/security/events/${activeSecurityEvent.value.id}`, securityEventForm.value);
        securityEventDialogOpen.value = false;
        securityNotice.value = 'Security event updated.';
        await loadSecurity();
    } catch (error) {
        const errors = error.response?.data?.errors;
        securityError.value = errors
            ? Object.values(errors).flat()[0]
            : error.response?.data?.message ?? 'The security event could not be updated.';
    } finally {
        securityEventSaving.value = false;
    }
}

const canManageSecurity = computed(
    () => platform.value?.user?.permissions?.includes('security.manage') ?? false,
);

function securitySeverityTag(severity) {
    return {
        critical: 'danger',
        high: 'danger',
        medium: 'warn',
        low: 'info',
        info: 'secondary',
    }[severity] ?? 'secondary';
}

function securityStatusTag(status) {
    return {
        open: 'danger',
        acknowledged: 'warn',
        resolved: 'success',
        false_positive: 'secondary',
    }[status] ?? 'secondary';
}

function securityScoreTone(score) {
    if (score === null || score === undefined) return 'secondary';
    if (score >= 85) return 'success';
    if (score >= 70) return 'warn';

    return 'danger';
}

/** Minutes -> human readable duration. */
function formatDuration(minutes) {
    if (minutes === null || minutes === undefined) return '—';
    if (minutes < 1) return '<1 min';
    if (minutes < 60) return `${Math.round(minutes)} min`;
    if (minutes < 1440) return `${(minutes / 60).toFixed(1)} hrs`;

    return `${(minutes / 1440).toFixed(1)} days`;
}

function formatDateTime(value) {
    if (!value) return '—';

    return new Date(value).toLocaleString();
}

/* --- Charts --- */

const securityTrendChartOptions = computed(() => ({
    chart: { type: 'area', toolbar: { show: false }, fontFamily: 'Inter, sans-serif', stacked: false },
    colors: ['#d05c5c', '#f2aa4c'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
    legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px' },
    xaxis: {
        categories: security.value?.threats?.trend?.map((point) => point.date) ?? [],
        labels: { rotate: -45, style: { fontSize: '10px' } },
        tickAmount: 10,
    },
    yaxis: { labels: { formatter: (value) => Math.round(value).toLocaleString() } },
    noData: { text: 'No security telemetry for this period.' },
}));

const securityTrendChartSeries = computed(() => [
    {
        name: 'Security findings',
        data: security.value?.threats?.trend?.map((point) => point.events) ?? [],
    },
    {
        name: 'Failed logins',
        data: security.value?.threats?.trend?.map((point) => point.failed_logins) ?? [],
    },
]);

const severityChartOptions = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
    colors: ['#b42318', '#d05c5c', '#f2aa4c', '#4c7ba8', '#657b76'],
    labels: security.value?.threats?.severity_breakdown?.map((row) => row.label) ?? [],
    legend: { position: 'bottom', fontSize: '11px' },
    dataLabels: { enabled: true },
    stroke: { colors: ['#fff'] },
    noData: { text: 'No open findings.' },
}));

const severityChartSeries = computed(
    () => security.value?.threats?.severity_breakdown?.map((row) => row.value) ?? [],
);

const complianceChartOptions = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
    colors: ['#19a7a0'],
    plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
    dataLabels: {
        enabled: true,
        formatter: (value) => `${value}%`,
        style: { fontSize: '11px' },
    },
    xaxis: {
        categories: security.value?.compliance?.by_framework?.map((row) => row.framework) ?? [],
        max: 100,
        labels: { formatter: (value) => `${Math.round(value)}%` },
    },
    noData: { text: 'No compliance data.' },
}));

const complianceChartSeries = computed(() => [{
    name: 'Controls passed',
    data: security.value?.compliance?.by_framework?.map((row) => row.percentage) ?? [],
}]);

const authChartOptions = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
    colors: ['#19a7a0', '#d05c5c'],
    labels: ['Successful', 'Failed'],
    legend: { position: 'bottom', fontSize: '11px' },
    dataLabels: { enabled: true },
    stroke: { colors: ['#fff'] },
    noData: { text: 'No authentication activity.' },
}));

const authChartSeries = computed(() => [
    security.value?.identity?.authentication?.successful ?? 0,
    security.value?.identity?.authentication?.failed ?? 0,
]);

const coverageSections = computed(() => {
    const value = security.value;

    if (!value) return [];

    return [
        value.vulnerability_management,
        value.endpoint_security,
        value.email_security,
        value.cloud_security,
    ].filter(Boolean);
});

function ageSeverity(days) {
    if (days >= 30) return 'danger';
    if (days >= 14) return 'warn';

    return 'success';
}

async function saveReport() {
    reportSaving.value = true;
    reportError.value = '';

    try {
        const response = editingReportId.value
            ? await axios.put(`/api/reports/${editingReportId.value}`, reportForm.value)
            : await axios.post('/api/reports', reportForm.value);
        reportDialogOpen.value = false;
        await loadReports(response.data.data.id);
        await loadPlatform();
    } catch (error) {
        const errors = error.response?.data?.errors;
        reportError.value = errors
            ? Object.values(errors).flat()[0]
            : error.response?.data?.message ?? 'The report definition could not be saved.';
    } finally {
        reportSaving.value = false;
    }
}

async function generateReport(report) {
    reportLoading.value = true;
    reportError.value = '';

    try {
        const { data } = await axios.post(`/api/reports/${report.id}/generate`, cleanFilters());
        activeReport.value = data.data;
        await loadReports(report.id);
    } catch (error) {
        reportError.value = error.response?.data?.message ?? 'The report could not be refreshed.';
    } finally {
        reportLoading.value = false;
    }
}

async function exportReport(report, format) {
    reportError.value = '';

    try {
        const response = await axios.get(`/api/reports/${report.id}/export/${format}`, {
            params: cleanFilters(),
            responseType: 'blob',
        });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${report.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.${format}`;
        link.click();
        URL.revokeObjectURL(url);
    } catch (error) {
        reportError.value = 'Generate this report before exporting it.';
    }
}

function reportChartOptions(report) {
    const chart = report.definition?.chart ?? {};

    return {
        chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
        colors: ['#19a7a0', '#146c94'],
        dataLabels: { enabled: chart.type === 'donut' },
        stroke: { curve: 'smooth', width: 3 },
        grid: { borderColor: '#e8eceb', strokeDashArray: 4 },
        labels: report.rows?.map((row) => String(row[chart.category_key] ?? 'Unknown')) ?? [],
        xaxis: {
            categories: report.rows?.map((row) => String(row[chart.category_key] ?? 'Unknown')) ?? [],
            labels: { style: { colors: '#73807c', fontSize: '11px' } },
        },
        legend: { position: 'bottom' },
        noData: { text: 'Connect and refresh this report to populate the chart.' },
    };
}

function reportChartSeries(report) {
    const chart = report.definition?.chart ?? {};
    const values = report.rows?.map((row) => Number(row[chart.value_key] ?? 0)) ?? [];

    return chart.type === 'donut' ? values : [{ name: chart.title ?? report.name, data: values }];
}

function formatCell(value, type) {
    if (value === null || value === undefined || value === '') return '—';
    if (type === 'currency') return new Intl.NumberFormat('en-AE', { style: 'currency', currency: 'AED', maximumFractionDigits: 0 }).format(value);
    if (type === 'percentage') return `${Number(value).toLocaleString()}%`;
    if (type === 'number') return Number(value).toLocaleString();
    return value;
}

async function loadSchedules() {
    schedulesLoading.value = true;
    scheduleError.value = '';

    try {
        const { data } = await axios.get('/api/schedules');
        schedules.value = data;
    } catch (error) {
        scheduleError.value = error.response?.status === 403
            ? 'Your account is not authorized to manage scheduled reports.'
            : 'Scheduled reports could not be loaded.';
    } finally {
        schedulesLoading.value = false;
    }
}

function openCreateSchedule() {
    editingScheduleId.value = null;
    scheduleForm.value = emptyScheduleForm();
    scheduleDialogOpen.value = true;
    scheduleError.value = '';
}

function openEditSchedule(schedule) {
    editingScheduleId.value = schedule.id;
    scheduleForm.value = {
        report_id: schedule.report.id,
        frequency: schedule.frequency,
        cron_expression: schedule.cron_expression,
        timezone: schedule.timezone,
        format: schedule.format,
        filters: {
            date_from: schedule.filters?.date_from ?? '',
            date_to: schedule.filters?.date_to ?? '',
            region: schedule.filters?.region ?? '',
            status: schedule.filters?.status ?? '',
        },
        delivery_channels: [...schedule.delivery_channels],
        recipients_text: schedule.recipients.join(', '),
        is_active: schedule.is_active,
    };
    scheduleDialogOpen.value = true;
}

function applyFrequencyPreset() {
    scheduleForm.value.cron_expression = {
        daily: '0 8 * * *',
        weekly: '0 8 * * 1',
        monthly: '0 8 1 * *',
        custom: scheduleForm.value.cron_expression,
    }[scheduleForm.value.frequency];
}

async function saveSchedule() {
    scheduleSaving.value = true;
    scheduleError.value = '';

    // Convert Date objects to YYYY-MM-DD format
    const filters = Object.fromEntries(
        Object.entries(scheduleForm.value.filters).map(([key, value]) => {
            if ((key === 'date_from' || key === 'date_to') && value instanceof Date) {
                return [key, value.toISOString().split('T')[0]];
            }
            return [key, value];
        }).filter(([, value]) => value),
    );

    const payload = {
        ...scheduleForm.value,
        recipients: scheduleForm.value.recipients_text
            .split(',')
            .map((email) => email.trim())
            .filter(Boolean),
        filters,
    };
    delete payload.recipients_text;

    try {
        if (editingScheduleId.value) {
            await axios.put(`/api/schedules/${editingScheduleId.value}`, payload);
        } else {
            await axios.post('/api/schedules', payload);
        }

        scheduleDialogOpen.value = false;
        await loadSchedules();
    } catch (error) {
        const errors = error.response?.data?.errors;
        scheduleError.value = errors
            ? Object.values(errors).flat()[0]
            : error.response?.data?.message ?? 'The schedule could not be saved.';
    } finally {
        scheduleSaving.value = false;
    }
}

async function runSchedule(schedule) {
    runningScheduleId.value = schedule.id;
    scheduleError.value = '';

    try {
        await axios.post(`/api/schedules/${schedule.id}/run`);
        await loadSchedules();
    } catch (error) {
        scheduleError.value = error.response?.data?.message ?? 'The report delivery could not be queued.';
    } finally {
        runningScheduleId.value = null;
    }
}

async function removeSchedule(schedule) {
    if (!window.confirm(`Remove the schedule for "${schedule.report.name}"?`)) {
        return;
    }

    await axios.delete(`/api/schedules/${schedule.id}`);
    await loadSchedules();
}

function scheduleSeverity(status) {
    return { succeeded: 'success', failed: 'danger', running: 'info', queued: 'warn' }[status] ?? 'secondary';
}

async function loadAnalytics() {
    analyticsLoading.value = true;
    analyticsError.value = '';

    try {
        const { data } = await axios.get('/api/analytics');
        analytics.value = data;

        if (!analyticsReportId.value && data.reports.length) {
            analyticsReportId.value = data.reports[0].id;
        }
    } catch (error) {
        analyticsError.value = error.response?.status === 403
            ? 'Your account is not authorized to view advanced analytics.'
            : 'Advanced analytics could not be loaded.';
    } finally {
        analyticsLoading.value = false;
    }
}

async function generateAnalytics() {
    if (!analyticsReportId.value) return;

    generatingAnalyticsId.value = analyticsReportId.value;
    analyticsError.value = '';

    try {
        await axios.post(`/api/analytics/reports/${analyticsReportId.value}`);
        await loadAnalytics();
    } catch (error) {
        analyticsError.value = error.response?.data?.message ?? 'Analytics could not be generated for this report.';
    } finally {
        generatingAnalyticsId.value = null;
    }
}

function insightIcon(type) {
    return {
        trend: 'pi-chart-line',
        anomaly: 'pi-exclamation-triangle',
        forecast: 'pi-forward',
        recommendation: 'pi-lightbulb',
    }[type] ?? 'pi-sparkles';
}

function insightSeverity(severity) {
    return { warning: 'warn', action: 'danger', info: 'info' }[severity] ?? 'secondary';
}

async function applyDeepLink() {
    const reportId = Number(new URLSearchParams(window.location.search).get('report'));

    if (reportId > 0 && platform.value?.user?.permissions.includes('reports.view')) {
        currentView.value = 'reports';
        await loadReports(reportId);
    }
}

async function initializeApp() {
    await loadPlatform();

    // Adopt the route the user actually landed on. Without this, a direct hit
    // on /users renders the users view with its initial empty state because
    // nothing has fetched the data.
    const routeName = legacyRoute.name;

    if (routeName && ! MIGRATED_VIEWS[routeName]) {
        currentView.value = routeName;
        await loadViewData(routeName);
    }

    await applyDeepLink();
}

onMounted(() => {
    currentDateTime.value = new Date();
    clockIntervalId = window.setInterval(() => {
        currentDateTime.value = new Date();
    }, 60_000);
    initializeApp();
});

onBeforeUnmount(() => {
    if (clockIntervalId !== null) {
        window.clearInterval(clockIntervalId);
    }
});
</script>

<template>
    <div v-if="loading" class="loading-screen">
        <div class="brand-mark"><i class="pi pi-sparkles"></i></div>
        <span>Preparing your intelligence workspace…</span>
    </div>

    <main v-else-if="!platform" class="auth-shell">
        <section class="auth-story">
            <div class="story-content">
                <div class="brand">
                    <div class="brand-mark"><i class="pi pi-sparkles"></i></div>
                    <span>Ask GAHolding</span>
                </div>
                <p class="eyebrow">Enterprise intelligence, one question away</p>
                <h1>Turn scattered business data into decisions.</h1>
                <p class="story-copy">
                    A secure reporting workspace connecting your teams, systems, and
                    performance data through natural language.
                </p>
                <div class="story-points">
                    <div><i class="pi pi-check"></i><span>Role-aware insights and dashboards</span></div>
                    <div><i class="pi pi-check"></i><span>Auditable, approved data access</span></div>
                    <div><i class="pi pi-check"></i><span>Reports built for every department</span></div>
                </div>
            </div>
            <div class="signal-card">
                <span class="signal-label">Platform rollout</span>
                <strong>Advanced analytics ready</strong>
                <div class="signal-line"><span></span></div>
                <small>Phase 6 of 6</small>
            </div>
        </section>

        <section class="auth-panel">
            <form class="login-card" @submit.prevent="login">
                <div class="mobile-brand">
                    <div class="brand-mark"><i class="pi pi-sparkles"></i></div>
                    <span>Ask GAHolding</span>
                </div>
                <div>
                    <p class="eyebrow">Welcome back</p>
                    <h2>Sign in to your workspace</h2>
                    <p class="form-intro">Use your organization account to continue.</p>
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <InputText id="email" v-model="credentials.email" type="email" autocomplete="username" fluid />
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <Password
                        input-id="password"
                        v-model="credentials.password"
                        :feedback="false"
                        toggle-mask
                        autocomplete="current-password"
                        fluid
                        :input-style="{ width: '100%' }"
                    />
                </div>

                <p v-if="loginError" class="form-error" role="alert">
                    <i class="pi pi-exclamation-circle"></i>{{ loginError }}
                </p>

                <Button type="submit" label="Sign in securely" icon="pi pi-arrow-right" icon-pos="right" :loading="submitting" />
                <p class="demo-note">Access is restricted to accounts provisioned by your platform administrator.</p>
            </form>
        </section>
    </main>

    <div v-else class="app-shell">
        <aside :class="['sidebar', { open: sidebarOpen }]">
            <div class="brand sidebar-brand">
                <div class="brand-mark"><i class="pi pi-sparkles"></i></div>
                <div><span>Ask GAHolding</span><small>INTELLIGENCE</small></div>
            </div>

            <nav aria-label="Primary navigation">
                <p v-if="navItems.length" class="nav-label">Workspace</p>
                <button
                    v-for="item in navItems"
                    :key="item.label"
                    :class="['nav-item', { active: currentView === item.id }]"
                    type="button"
                    @click="setView(item.id)"
                >
                    <i :class="['pi', item.icon]"></i>
                    <span>{{ item.label }}</span>
                    <small v-if="item.phase">{{ item.phase }}</small>
                </button>

                <p v-if="adminItems.length" class="nav-label admin-label">Administration</p>
                <button
                    v-for="item in adminItems"
                    :key="item.label"
                    :class="['nav-item', { active: currentView === item.id }]"
                    type="button"
                    @click="setView(item.id)"
                >
                    <i :class="['pi', item.icon]"></i>
                    <span>{{ item.label }}</span>
                    <small v-if="item.phase">{{ item.phase }}</small>
                </button>
            </nav>

            <div class="sidebar-profile">
                <UserAccountLink :user="platform.user" />
                <button type="button" title="Sign out" aria-label="Sign out" @click="logout"><i class="pi pi-sign-out"></i></button>
            </div>
        </aside>

        <button v-if="sidebarOpen" class="sidebar-backdrop" aria-label="Close menu" @click="sidebarOpen = false"></button>

        <section class="workspace">
            <header class="topbar">
                <button class="menu-button" type="button" aria-label="Open menu" @click="sidebarOpen = true">
                    <i class="pi pi-bars"></i>
                </button>
                <div class="breadcrumb">
                    <span>{{ ['integrations', 'users', 'audit'].includes(currentView) ? 'Administration' : ['ai', 'dashboards', 'reports', 'schedules'].includes(currentView) ? 'Intelligence' : 'Workspace' }}</span>
                    <i class="pi pi-angle-right"></i>
                    <strong>{{ currentViewLabel }}</strong>
                </div>
                <div class="topbar-actions">
                    <Tag severity="success" value="Phase 6 live" icon="pi pi-check-circle" />
                    <Button icon="pi pi-bell" severity="secondary" text rounded aria-label="Notifications" />
                </div>
            </header>

            <div class="content">
                <template v-if="currentView === 'overview'">
                <section class="welcome-row">
                    <div>
                        <p class="eyebrow">{{ overviewDate }}</p>
                        <h1>{{ greeting }}, {{ platform.user.name.split(' ')[0] }}.</h1>
                        <p>Your governed intelligence platform is ready. Here’s the current enterprise view.</p>
                    </div>
                    <Button label="Ask GAHolding" icon="pi pi-sparkles" @click="setView('ai')" />
                </section>

                <section class="metric-grid" aria-label="Platform metrics">
                    <article v-for="metric in platform.metrics" :key="metric.label" class="metric-card">
                        <div class="metric-icon"><i :class="['pi', metric.icon]"></i></div>
                        <div>
                            <span>{{ metric.label }}</span>
                            <strong>{{ metric.value }}</strong>
                            <small>{{ metric.detail }}</small>
                        </div>
                    </article>
                </section>

                <section class="dashboard-grid">
                    <article class="panel rollout-panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Delivery roadmap</p>
                                <h2>Platform rollout</h2>
                            </div>
                            <span class="updated">Updated just now</span>
                        </div>
                        <apexchart type="bar" height="280" :options="chartOptions" :series="chartSeries" />
                    </article>

                    <article class="panel readiness-panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Security baseline</p>
                                <h2>Foundation readiness</h2>
                            </div>
                            <div class="readiness-score">100<small>%</small></div>
                        </div>
                        <div class="readiness-list">
                            <div>
                                <span><i class="pi pi-check-circle"></i>Authentication</span>
                                <Tag severity="success" value="Ready" />
                            </div>
                            <div>
                                <span><i class="pi pi-check-circle"></i>Role-based access</span>
                                <Tag severity="success" value="Ready" />
                            </div>
                            <div>
                                <span><i class="pi pi-check-circle"></i>Audit logging</span>
                                <Tag severity="success" value="Ready" />
                            </div>
                            <div>
                                <span><i class="pi pi-check-circle"></i>Core data model</span>
                                <Tag severity="success" value="Ready" />
                            </div>
                        </div>
                    </article>
                </section>

                <section class="panel phase-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Implementation status</p>
                            <h2>Phase-by-phase delivery</h2>
                        </div>
                        <span class="muted">{{ platform.phases.filter((phase) => phase.progress === 100).length }} of {{ platform.phases.length }} product stages established</span>
                    </div>
                    <div class="phase-list">
                        <div v-for="(phase, index) in platform.phases" :key="phase.name" class="phase-row">
                            <span class="phase-number">{{ String(index + 1).padStart(2, '0') }}</span>
                            <div class="phase-info">
                                <div><strong>{{ phase.name }}</strong><span>{{ phase.progress }}%</span></div>
                                <ProgressBar :value="phase.progress" :show-value="false" />
                            </div>
                            <Tag
                                :severity="phase.status === 'active' ? 'success' : phase.status === 'next' ? 'info' : 'secondary'"
                                :value="phase.status === 'active' ? 'Complete' : phase.status"
                            />
                        </div>
                    </div>
                </section>
                </template>

                <template v-else-if="currentView === 'ai'">
                    <section class="welcome-row ai-heading">
                        <div>
                            <p class="eyebrow">Governed natural-language reporting</p>
                            <h1>Ask GAHolding</h1>
                            <p>Explore approved enterprise data with traceable tools and source citations.</p>
                        </div>
                        <div class="ai-model-badge">
                            <span :class="['status-dot', { online: aiStatus.configured }]"></span>
                            <div>
                                <strong>{{ aiStatus.model || 'AI provider' }}</strong>
                                <small>{{ aiStatus.configured ? 'Ready' : 'Configuration required' }}</small>
                            </div>
                        </div>
                    </section>

                    <Message v-if="aiError" severity="error" closable @close="aiError = ''">
                        {{ aiError }}
                    </Message>

                    <Message v-if="!aiLoading && !aiStatus.configured" severity="warn" :closable="false">
                        The {{ aiStatus.provider }} provider is not configured. Add its environment credential to enable live report conversations.
                    </Message>

                    <section class="chat-workspace">
                        <aside class="conversation-rail">
                            <Button label="New conversation" icon="pi pi-plus" outlined fluid @click="startNewConversation()" />
                            <div class="conversation-list">
                                <p class="nav-label">History</p>
                                <div v-if="aiLoading" class="conversation-loading">
                                    <i class="pi pi-spin pi-spinner"></i>
                                </div>
                                <button
                                    v-for="conversation in conversations"
                                    :key="conversation.id"
                                    :class="['conversation-item', { active: activeConversation?.id === conversation.id }]"
                                    type="button"
                                    @click="openConversation(conversation)"
                                >
                                    <i class="pi pi-comment"></i>
                                    <div>
                                        <strong>{{ conversation.title }}</strong>
                                        <span>{{ conversation.messages_count }} messages</span>
                                    </div>
                                    <i class="pi pi-trash remove-chat" @click.stop="removeConversation(conversation)"></i>
                                </button>
                                <p v-if="!aiLoading && !conversations.length" class="conversation-empty">
                                    Your report conversations will appear here.
                                </p>
                            </div>
                            <div class="tool-policy">
                                <i class="pi pi-shield"></i>
                                <div><strong>Read-only tools</strong><span>{{ aiStatus.tools.length }} approved reporting functions</span></div>
                            </div>
                        </aside>

                        <div class="chat-panel">
                            <div class="chat-panel-header">
                                <div class="gaholding-avatar"><i class="pi pi-sparkles"></i></div>
                                <div>
                                    <strong>{{ activeConversation?.title || 'New intelligence request' }}</strong>
                                    <span>Grounded in connected, authorized sources</span>
                                </div>
                                <Tag severity="success" value="Audited" icon="pi pi-shield" />
                            </div>

                            <div class="message-stream">
                                <div v-if="!chatMessages.length" class="chat-welcome">
                                    <div class="welcome-spark"><i class="pi pi-sparkles"></i></div>
                                    <h2>What would you like to understand?</h2>
                                    <p>Ask GAHolding uses only approved read tools and identifies the source behind every retrieved result.</p>
                                    <div class="suggested-prompts">
                                        <button type="button" @click="useSuggestedPrompt('Summarize our CRM pipeline for this month and highlight the largest risks.')">
                                            <i class="pi pi-filter"></i>
                                            <span>Review CRM pipeline</span>
                                        </button>
                                        <button type="button" @click="useSuggestedPrompt('Compare procurement spend this month with the previous month.')">
                                            <i class="pi pi-shopping-cart"></i>
                                            <span>Compare procurement spend</span>
                                        </button>
                                        <button type="button" @click="useSuggestedPrompt('Show website traffic and conversion performance for the last 30 days.')">
                                            <i class="pi pi-chart-line"></i>
                                            <span>Analyze website performance</span>
                                        </button>
                                    </div>
                                    <div v-if="aiStatus.sources.length" class="available-sources">
                                        <span>Available sources</span>
                                        <Tag v-for="source in aiStatus.sources" :key="source.id" severity="secondary" :value="source.name" />
                                    </div>
                                </div>

                                <article
                                    v-for="message in chatMessages"
                                    :key="message.id"
                                    :class="['chat-message', message.role]"
                                >
                                    <div class="message-avatar">
                                        <i :class="['pi', message.role === 'assistant' ? 'pi-sparkles' : 'pi-user']"></i>
                                    </div>
                                    <div class="message-body">
                                        <div class="message-author">
                                            <strong>{{ message.role === 'assistant' ? 'Ask GAHolding' : platform.user.name }}</strong>
                                        </div>

                                        <!--
                                            Only the assistant's side is rendered as Markdown. What the
                                            user typed is shown verbatim, so a stray asterisk in a
                                            question is never reinterpreted as formatting.
                                        -->
                                        <div v-if="message.role === 'assistant'" class="answer-card">
                                            <!-- eslint-disable-next-line vue/no-v-html -- escaped upstream in useAnswerFormat -->
                                            <div class="answer-prose" v-html="renderAnswer(message.content)"></div>

                                            <footer class="answer-meta">
                                                <div class="answer-meta-row">
                                                    <div v-if="message.citations?.length" class="message-citations">
                                                        <span>Sourced from</span>
                                                        <Tag
                                                            v-for="citation in message.citations"
                                                            :key="`${message.id}-${citation.source_id}`"
                                                            severity="secondary"
                                                            :value="citation.source_name"
                                                            icon="pi pi-database"
                                                        />
                                                    </div>

                                                    <button type="button" class="answer-copy" @click="copyAnswer(message)">
                                                        <i :class="['pi', copiedMessageId === message.id ? 'pi-check' : 'pi-copy']"></i>
                                                        {{ copiedMessageId === message.id ? 'Copied' : 'Copy' }}
                                                    </button>
                                                </div>

                                                <!--
                                                    Tool trace and response time are the audit trail, not the
                                                    answer. Folded away so the figures lead, but one click from
                                                    anyone who has to defend where a number came from.
                                                -->
                                                <details v-if="message.tool_calls?.length || message.latency_ms" class="answer-trace">
                                                    <summary>How this was produced</summary>
                                                    <div class="tool-activity">
                                                        <span
                                                            v-for="tool in message.tool_calls"
                                                            :key="`${message.id}-${tool.name}`"
                                                        >
                                                            <i class="pi pi-bolt"></i>{{ tool.name.replaceAll('_', ' ') }}
                                                        </span>
                                                        <span v-if="message.latency_ms">
                                                            <i class="pi pi-clock"></i>Answered in {{ formatLatency(message.latency_ms) }}
                                                        </span>
                                                    </div>
                                                </details>
                                            </footer>
                                        </div>

                                        <p v-else>{{ message.content }}</p>
                                    </div>
                                </article>

                                <article v-if="chatSending" class="chat-message assistant">
                                    <div class="message-avatar"><i class="pi pi-sparkles"></i></div>
                                    <div class="message-body thinking">
                                        <span></span><span></span><span></span>
                                        <small>Reviewing approved sources…</small>
                                    </div>
                                </article>
                            </div>

                            <form class="chat-composer" @submit.prevent="sendChat">
                                <Textarea
                                    v-model="chatInput"
                                    rows="2"
                                    auto-resize
                                    :disabled="!aiStatus.configured || chatSending"
                                    placeholder="Ask about sales, pipeline, assets, procurement, or website performance…"
                                    aria-label="Ask GAHolding"
                                    @keydown.enter.exact.prevent="sendChat"
                                />
                                <Button
                                    type="submit"
                                    icon="pi pi-arrow-up"
                                    rounded
                                    aria-label="Send message"
                                    :disabled="!chatInput.trim() || !aiStatus.configured"
                                    :loading="chatSending"
                                />
                                <small>AI responses can be inaccurate. Verify critical decisions against the cited source system.</small>
                            </form>
                        </div>
                    </section>
                </template>

                <template v-else-if="currentView === 'dashboards'">
                    <section class="welcome-row intelligence-heading">
                        <div>
                            <p class="eyebrow">Decision-ready performance views</p>
                            <h1>{{ activeDashboard?.name || 'Business dashboards' }}</h1>
                            <p>{{ activeDashboard?.description || 'Explore governed KPIs across every business function.' }}</p>
                        </div>
                        <Button label="Refresh view" icon="pi pi-refresh" outlined :loading="dashboardLoading" @click="loadDashboards()" />
                    </section>

                    <Message v-if="dashboardError" severity="error" closable @close="dashboardError = ''">
                        {{ dashboardError }}
                    </Message>

                    <div class="dashboard-tabs" aria-label="Dashboard selection">
                        <button
                            v-for="dashboard in dashboards"
                            :key="dashboard.id"
                            :class="{ active: activeDashboard?.slug === dashboard.slug }"
                            type="button"
                            @click="loadDashboards(dashboard.slug)"
                        >
                            {{ dashboard.name.replace(' Dashboard', '') }}
                        </button>
                    </div>

                    <section class="filter-bar panel">
                        <div class="filter-copy">
                            <i class="pi pi-filter"></i>
                            <div><strong>Focus the view</strong><span>Filters apply across every widget.</span></div>
                        </div>
                        <InputText v-model="reportFilters.date_from" type="date" aria-label="Start date" />
                        <InputText v-model="reportFilters.date_to" type="date" aria-label="End date" />
                        <InputText v-model="reportFilters.region" placeholder="Region" aria-label="Region" />
                        <Button label="Apply" icon="pi pi-check" @click="loadDashboards()" />
                    </section>

                    <section
                        v-if="activeDashboardSupportsSearchConsole && dashboardSearchConsoleSources.length"
                        class="panel search-console-dashboard"
                    >
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Google Search Console</p>
                                <h2>Organic search performance</h2>
                                <span class="muted">Read-only data retrieved directly from Google with the server-side service account.</span>
                            </div>
                            <Tag
                                :severity="selectedDashboardSearchConsoleSource?.status === 'connected' ? 'success' : 'danger'"
                                :value="selectedDashboardSearchConsoleSource?.status ?? 'not configured'"
                            />
                        </div>

                        <Message v-if="dashboardSearchConsoleError" severity="error" closable @close="dashboardSearchConsoleError = ''">
                            {{ dashboardSearchConsoleError }}
                        </Message>

                        <div class="search-console-filters">
                            <Select
                                v-model="dashboardSearchConsoleFilters.data_source_id"
                                :options="dashboardSearchConsoleSources"
                                option-label="name"
                                option-value="id"
                                aria-label="Search Console source"
                                fluid
                                @change="loadDashboardSearchConsole"
                            />
                            <Select
                                v-model="dashboardSearchConsoleFilters.dimension"
                                :options="[
                                    { label: 'Search queries', value: 'query' },
                                    { label: 'Landing pages', value: 'page' },
                                    { label: 'Countries', value: 'country' },
                                    { label: 'Devices', value: 'device' },
                                    { label: 'Daily trend', value: 'date' },
                                ]"
                                option-label="label"
                                option-value="value"
                                aria-label="Search Console breakdown"
                                fluid
                                @change="loadDashboardSearchConsole"
                            />
                            <InputText v-model="dashboardSearchConsoleFilters.date_from" type="date" aria-label="Search Console start date" />
                            <InputText v-model="dashboardSearchConsoleFilters.date_to" type="date" aria-label="Search Console end date" />
                            <Button
                                label="Load analytics"
                                icon="pi pi-chart-line"
                                :loading="dashboardSearchConsoleLoading"
                                :disabled="!dashboardSearchConsoleFilters.data_source_id"
                                @click="loadDashboardSearchConsole"
                            />
                        </div>

                        <template v-if="dashboardSearchConsole">
                            <section class="search-console-summary">
                                <article><span>Clicks</span><strong>{{ Number(dashboardSearchConsole.summary.clicks).toLocaleString() }}</strong></article>
                                <article><span>Impressions</span><strong>{{ Number(dashboardSearchConsole.summary.impressions).toLocaleString() }}</strong></article>
                                <article><span>CTR</span><strong>{{ dashboardSearchConsole.summary.ctr }}%</strong></article>
                                <article><span>Avg. position</span><strong>{{ dashboardSearchConsole.summary.position }}</strong></article>
                            </section>

                            <div class="search-console-dashboard-chart">
                                <apexchart
                                    :type="dashboardSearchConsole.summary.dimension === 'date' ? 'line' : 'bar'"
                                    height="300"
                                    :options="searchConsoleChartOptions()"
                                    :series="searchConsoleChartSeries()"
                                />
                            </div>

                            <div class="report-table-wrap">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th>{{ dashboardSearchConsole.summary.dimension }}</th>
                                            <th>Clicks</th>
                                            <th>Impressions</th>
                                            <th>CTR</th>
                                            <th>Avg. position</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, index) in dashboardSearchConsole.rows" :key="index">
                                            <td>{{ row[dashboardSearchConsole.summary.dimension] }}</td>
                                            <td>{{ Number(row.clicks).toLocaleString() }}</td>
                                            <td>{{ Number(row.impressions).toLocaleString() }}</td>
                                            <td>{{ row.ctr }}%</td>
                                            <td>{{ row.position }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                        <div v-else-if="selectedDashboardSearchConsoleSource?.status !== 'connected'" class="table-empty">
                            <i class="pi pi-exclamation-triangle"></i>
                            <strong>Google API setup is incomplete</strong>
                            <span>Enable the Search Console API, then have an administrator test the source from Data Sources.</span>
                        </div>
                    </section>

                    <section
                        v-if="activeDashboardSupportsFreshservice"
                        class="itsm-dashboard"
                    >
                        <div class="panel itsm-dashboard-header">
                            <div class="panel-heading">
                                <div>
                                    <p class="eyebrow">Freshservice ITSM</p>
                                    <h2>Ticket operations and SLA analytics</h2>
                                    <span class="muted">Live, read-only ticket metrics across all accessible Freshservice workspaces.</span>
                                </div>
                                <Tag
                                    :severity="selectedFreshserviceSource?.status === 'connected' ? 'success' : 'danger'"
                                    :value="selectedFreshserviceSource?.status ?? 'not configured'"
                                />
                            </div>

                            <Message v-if="freshserviceError" severity="error" closable @close="freshserviceError = ''">
                                {{ freshserviceError }}
                            </Message>

                            <div class="itsm-source-row">
                                <Select
                                    v-model="freshserviceSourceId"
                                    :options="freshserviceSources"
                                    option-label="name"
                                    option-value="id"
                                    placeholder="Select Freshservice source"
                                    aria-label="Freshservice source"
                                    fluid
                                    @change="loadFreshserviceAnalytics"
                                />
                                <Button
                                    label="Refresh ticket analytics"
                                    icon="pi pi-refresh"
                                    :loading="freshserviceLoading"
                                    :disabled="!freshserviceSourceId"
                                    @click="loadFreshserviceAnalytics"
                                />
                            </div>
                        </div>

                        <div v-if="freshserviceLoading && !freshserviceAnalytics" class="source-empty panel">
                            <i class="pi pi-spin pi-spinner"></i><span>Loading Freshservice analytics…</span>
                        </div>

                        <template v-else-if="freshserviceAnalytics">
                            <Message
                                v-if="freshserviceAnalytics.meta.unresolved_ticket_limit_reached"
                                severity="warn"
                                :closable="false"
                            >
                                The Freshservice unresolved-ticket safety limit was reached. Narrow the date range or increase the governed server limit before treating agent totals as complete.
                            </Message>
                            <section class="itsm-kpi-grid">
                                <article><i class="pi pi-inbox"></i><span>Open tickets</span><strong>{{ freshserviceAnalytics.summary.open.toLocaleString() }}</strong></article>
                                <article><i class="pi pi-pause-circle"></i><span>On hold</span><strong>{{ freshserviceAnalytics.summary.on_hold.toLocaleString() }}</strong></article>
                                <article class="kpi-danger"><i class="pi pi-exclamation-triangle"></i><span>Overdue</span><strong>{{ freshserviceAnalytics.summary.overdue.toLocaleString() }}</strong></article>
                                <article class="kpi-warning"><i class="pi pi-calendar-clock"></i><span>Due today</span><strong>{{ freshserviceAnalytics.summary.due_today.toLocaleString() }}</strong></article>
                                <article><i class="pi pi-user-minus"></i><span>Unassigned</span><strong>{{ freshserviceAnalytics.summary.unassigned.toLocaleString() }}</strong></article>
                                <article><i class="pi pi-ticket"></i><span>Unresolved</span><strong>{{ freshserviceAnalytics.summary.unresolved.toLocaleString() }}</strong></article>
                                <article class="kpi-danger"><i class="pi pi-shield"></i><span>SLA breached</span><strong>{{ freshserviceAnalytics.summary.sla_breached.toLocaleString() }}</strong></article>
                                <article><i class="pi pi-chart-bar"></i><span>Tickets analyzed</span><strong>{{ freshserviceAnalytics.summary.total.toLocaleString() }}</strong></article>
                            </section>

                            <section class="itsm-chart-grid">
                                <article class="panel itsm-chart-card">
                                    <div class="panel-heading"><div><p class="eyebrow">Overall Ticket Summary</p><h2>Tickets by status</h2></div></div>
                                    <apexchart
                                        type="donut"
                                        height="310"
                                        :options="itsmPieOptions(freshserviceAnalytics.overall_ticket_summary)"
                                        :series="itsmPieSeries(freshserviceAnalytics.overall_ticket_summary)"
                                    />
                                </article>

                                <article class="panel itsm-chart-card">
                                    <div class="panel-heading"><div><p class="eyebrow">Unresolved tickets</p><h2>By priority</h2></div></div>
                                    <apexchart
                                        type="pie"
                                        height="310"
                                        :options="itsmPieOptions(freshserviceAnalytics.unresolved_by_priority)"
                                        :series="itsmPieSeries(freshserviceAnalytics.unresolved_by_priority)"
                                    />
                                </article>

                                <article class="panel itsm-chart-card">
                                    <div class="panel-heading"><div><p class="eyebrow">Unresolved tickets</p><h2>By status</h2></div></div>
                                    <apexchart
                                        type="donut"
                                        height="310"
                                        :options="itsmPieOptions(freshserviceAnalytics.unresolved_by_status)"
                                        :series="itsmPieSeries(freshserviceAnalytics.unresolved_by_status)"
                                    />
                                </article>

                                <article class="panel itsm-chart-card">
                                    <div class="panel-heading"><div><p class="eyebrow">Unresolved tickets</p><h2>By type</h2></div></div>
                                    <apexchart
                                        type="pie"
                                        height="310"
                                        :options="itsmPieOptions(freshserviceAnalytics.unresolved_by_type)"
                                        :series="itsmPieSeries(freshserviceAnalytics.unresolved_by_type)"
                                    />
                                </article>

                                <article class="panel itsm-chart-card">
                                    <div class="panel-heading"><div><p class="eyebrow">All unresolved tickets</p><h2>By agent</h2></div></div>
                                    <apexchart
                                        type="bar"
                                        height="310"
                                        :options="itsmBarOptions(freshserviceAnalytics.unresolved_by_agent)"
                                        :series="itsmBarSeries(freshserviceAnalytics.unresolved_by_agent, 'Unresolved tickets')"
                                    />
                                </article>

                                <article class="panel itsm-chart-card">
                                    <div class="panel-heading"><div><p class="eyebrow">Unresolved tickets</p><h2>By support group</h2></div></div>
                                    <apexchart
                                        type="bar"
                                        :height="Math.max(310, (freshserviceAnalytics.unresolved_by_group?.length ?? 0) * 32)"
                                        :options="itsmBarOptions(freshserviceAnalytics.unresolved_by_group)"
                                        :series="itsmBarSeries(freshserviceAnalytics.unresolved_by_group, 'Unresolved tickets')"
                                    />
                                </article>

                                <article class="panel itsm-chart-card">
                                    <div class="panel-heading">
                                        <div>
                                            <p class="eyebrow">Backlog ageing</p>
                                            <h2>By days open</h2>
                                            <span class="muted">
                                                Average {{ ageingMeta.average }} days &middot; median {{ ageingMeta.median }} days
                                            </span>
                                        </div>
                                    </div>
                                    <apexchart
                                        type="bar"
                                        height="310"
                                        :options="ageingChartOptions"
                                        :series="ageingChartSeries"
                                    />
                                </article>

                                <article class="panel itsm-chart-card itsm-chart-wide">
                                    <div class="panel-heading"><div><p class="eyebrow">SLA breached tickets</p><h2>By group & agent</h2></div></div>
                                    <apexchart
                                        type="bar"
                                        :height="Math.max(320, slaGroupAgentRows().length * 34)"
                                        :options="itsmBarOptions(slaGroupAgentRows())"
                                        :series="itsmBarSeries(slaGroupAgentRows(), 'SLA breached tickets')"
                                    />
                                </article>

                                <article class="panel itsm-chart-card itsm-chart-wide">
                                    <div class="panel-heading">
                                        <div>
                                            <p class="eyebrow">SLA exposure</p>
                                            <h2>By category &rsaquo; sub category &rsaquo; item</h2>
                                            <span class="muted">Breached vs within-SLA unresolved tickets. Compliance is measured across currently unresolved tickets.</span>
                                        </div>
                                        <Button
                                            label="Reset"
                                            icon="pi pi-filter-slash"
                                            severity="secondary"
                                            text
                                            size="small"
                                            @click="resetSlaCategoryFilters"
                                        />
                                    </div>

                                    <div class="sla-category-toolbar">
                                        <div class="field">
                                            <label for="sla-category">Category</label>
                                            <Select
                                                id="sla-category"
                                                v-model="slaCategoryFilters.category"
                                                :options="slaCategoryOptions"
                                                option-label="label"
                                                option-value="value"
                                                placeholder="All categories"
                                                show-clear
                                                filter
                                                size="small"
                                                @change="onSlaCategoryChange"
                                            />
                                        </div>
                                        <div class="field">
                                            <label for="sla-sub-category">Sub Category</label>
                                            <Select
                                                id="sla-sub-category"
                                                v-model="slaCategoryFilters.sub_category"
                                                :options="slaSubCategoryOptions"
                                                option-label="label"
                                                option-value="value"
                                                placeholder="All sub categories"
                                                show-clear
                                                filter
                                                size="small"
                                                @change="onSlaSubCategoryChange"
                                            />
                                        </div>
                                        <div class="field">
                                            <label for="sla-item">Item</label>
                                            <Select
                                                id="sla-item"
                                                v-model="slaCategoryFilters.item"
                                                :options="slaItemOptions"
                                                option-label="label"
                                                option-value="value"
                                                placeholder="All items"
                                                show-clear
                                                filter
                                                size="small"
                                            />
                                        </div>
                                        <div class="field">
                                            <label for="sla-level">Group bars by</label>
                                            <Select
                                                id="sla-level"
                                                v-model="slaCategoryLevel"
                                                :options="slaCategoryLevelOptions"
                                                option-label="label"
                                                option-value="value"
                                                size="small"
                                            />
                                        </div>
                                    </div>

                                    <div class="sla-category-stats">
                                        <span><strong>{{ slaCategoryTotals.total.toLocaleString() }}</strong> unresolved</span>
                                        <span class="sla-stat-danger"><strong>{{ slaCategoryTotals.breached.toLocaleString() }}</strong> SLA breached</span>
                                        <span><strong>{{ slaCategoryTotals.compliant.toLocaleString() }}</strong> within SLA</span>
                                        <Tag
                                            :severity="slaComplianceSeverity(slaCategoryTotals.compliance)"
                                            :value="`${slaCategoryTotals.compliance}% compliance`"
                                        />
                                    </div>

                                    <apexchart
                                        type="bar"
                                        :height="Math.max(320, slaCategoryChartRows.length * 34)"
                                        :options="slaCategoryChartOptions"
                                        :series="slaCategoryChartSeries"
                                    />

                                    <table v-if="slaCategoryChartRows.length" class="sla-category-table">
                                        <thead>
                                            <tr>
                                                <th>{{ slaCategoryLevelOptions.find((option) => option.value === slaCategoryLevel)?.label }}</th>
                                                <th class="numeric">Unresolved</th>
                                                <th class="numeric">Breached</th>
                                                <th class="numeric">Within SLA</th>
                                                <th class="numeric">Compliance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="row in slaCategoryChartRows" :key="row.label">
                                                <td>{{ row.label }}</td>
                                                <td class="numeric">{{ row.total.toLocaleString() }}</td>
                                                <td class="numeric sla-stat-danger">{{ row.breached.toLocaleString() }}</td>
                                                <td class="numeric">{{ row.compliant.toLocaleString() }}</td>
                                                <td class="numeric">
                                                    <Tag
                                                        :severity="slaComplianceSeverity(row.compliance)"
                                                        :value="`${row.compliance}%`"
                                                    />
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </article>

                                <article class="panel itsm-chart-card itsm-chart-wide">
                                    <div class="panel-heading">
                                        <div>
                                            <p class="eyebrow">All unresolved tickets</p>
                                            <h2>By agent &amp; status</h2>
                                            <span class="muted">
                                                Top {{ agentMatrixRows.length }} agents by backlog &middot;
                                                {{ agentMatrix.grand_total.toLocaleString() }} unresolved tickets in total
                                            </span>
                                        </div>
                                    </div>
                                    <apexchart
                                        type="bar"
                                        :height="Math.max(340, agentMatrixRows.length * 32)"
                                        :options="agentMatrixChartOptions"
                                        :series="agentMatrixChartSeries"
                                    />
                                </article>

                                <article class="panel itsm-chart-card itsm-chart-wide">
                                    <div class="panel-heading">
                                        <div>
                                            <p class="eyebrow">For immediate action</p>
                                            <h2>Unresolved P1 &amp; P2 tickets</h2>
                                            <span class="muted">Urgent and High priority tickets still open, oldest first.</span>
                                        </div>
                                        <InputText
                                            v-model="criticalTicketSearch"
                                            placeholder="Search ticket, agent, group…"
                                            size="small"
                                        />
                                    </div>

                                    <div class="itsm-table-scroll">
                                        <table class="sla-category-table">
                                            <thead>
                                                <tr>
                                                    <th>Ticket</th>
                                                    <th>Priority</th>
                                                    <th>Group</th>
                                                    <th>Agent</th>
                                                    <th>Status</th>
                                                    <th class="numeric">Age</th>
                                                    <th>Subject</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="ticket in filteredCriticalTickets" :key="ticket.id">
                                                    <td>#{{ ticket.id }}</td>
                                                    <td><Tag :severity="prioritySeverity(ticket.priority)" :value="ticket.priority" /></td>
                                                    <td>{{ ticket.group }}</td>
                                                    <td>{{ ticket.agent }}</td>
                                                    <td>{{ ticket.status }}</td>
                                                    <td class="numeric">
                                                        <Tag :severity="ageSeverity(ticket.pending_days)" :value="`${ticket.pending_days}d`" />
                                                    </td>
                                                    <td class="sla-subject">{{ ticket.subject }}</td>
                                                </tr>
                                                <tr v-if="!filteredCriticalTickets.length">
                                                    <td colspan="7" class="sla-empty">
                                                        {{ criticalTicketSearch
                                                            ? 'No tickets match your search.'
                                                            : 'No unresolved Urgent or High priority tickets.' }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </article>

                                <article v-if="ageingMeta.oldest.length" class="panel itsm-chart-card itsm-chart-wide">
                                    <div class="panel-heading">
                                        <div>
                                            <p class="eyebrow">Longest open</p>
                                            <h2>Oldest unresolved tickets</h2>
                                            <span class="muted">The ten tickets that have been open the longest, regardless of priority.</span>
                                        </div>
                                    </div>

                                    <div class="itsm-table-scroll">
                                        <table class="sla-category-table">
                                            <thead>
                                                <tr>
                                                    <th>Ticket</th>
                                                    <th class="numeric">Days open</th>
                                                    <th>Priority</th>
                                                    <th>Status</th>
                                                    <th>Group</th>
                                                    <th>Agent</th>
                                                    <th>Subject</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="ticket in ageingMeta.oldest" :key="`oldest-${ticket.id}`">
                                                    <td>#{{ ticket.id }}</td>
                                                    <td class="numeric">
                                                        <Tag :severity="ageSeverity(ticket.pending_days)" :value="`${ticket.pending_days}d`" />
                                                    </td>
                                                    <td>{{ ticket.priority }}</td>
                                                    <td>{{ ticket.status }}</td>
                                                    <td>{{ ticket.group }}</td>
                                                    <td>{{ ticket.agent }}</td>
                                                    <td class="sla-subject">{{ ticket.subject }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </article>
                            </section>

                            <footer class="itsm-evidence">
                                <span><i class="pi pi-database"></i>{{ freshserviceAnalytics.citation.source_name }}</span>
                                <span><i class="pi pi-clock"></i>Generated {{ new Date(freshserviceAnalytics.meta.generated_at).toLocaleString() }}</span>
                                <span><i class="pi pi-globe"></i>{{ freshserviceAnalytics.meta.timezone }}</span>
                                <span><i class="pi pi-info-circle"></i>Live API data; Freshservice Analytics widgets may refresh later.</span>
                            </footer>
                        </template>

                        <div v-else class="table-empty panel">
                            <i class="pi pi-ticket"></i>
                            <strong>No connected Freshservice source is available</strong>
                            <span>Add or test the Freshservice API source from Administration → Data sources.</span>
                        </div>
                    </section>

                    <div v-if="dashboardLoading && !activeDashboard" class="source-empty panel">
                        <i class="pi pi-spin pi-spinner"></i><span>Loading dashboard data…</span>
                    </div>

                    <section v-else-if="activeDashboard" class="business-dashboard-grid">
                        <article
                            v-for="report in activeDashboard.reports"
                            :key="report.id"
                            :class="['panel', 'dashboard-widget', `widget-${report.widget.size}`]"
                        >
                            <div class="panel-heading">
                                <div>
                                    <p class="eyebrow">{{ report.type.replaceAll('_', ' ') }}</p>
                                    <h2>{{ report.name }}</h2>
                                </div>
                                <Tag
                                    :severity="report.last_generated_at ? 'success' : 'secondary'"
                                    :value="report.last_generated_at ? `${report.rows.length} records` : 'Awaiting data'"
                                />
                            </div>
                            <apexchart
                                :type="report.definition.chart?.type || 'bar'"
                                height="260"
                                :options="reportChartOptions(report)"
                                :series="reportChartSeries(report)"
                            />
                            <div class="widget-footer">
                                <span v-if="report.citations?.length">
                                    <i class="pi pi-database"></i>{{ report.citations[0].source_name }}
                                </span>
                                <span v-else><i class="pi pi-link"></i>Assign a source in Reports</span>
                                <Button label="Open report" icon="pi pi-arrow-right" icon-pos="right" text size="small" @click="setView('reports').then(() => openReport(report.id))" />
                            </div>
                        </article>
                    </section>
                </template>

                <template v-else-if="currentView === 'reports'">
                    <section class="welcome-row intelligence-heading">
                        <div>
                            <p class="eyebrow">Reusable, governed reporting</p>
                            <h1>Report library</h1>
                            <p>Configure source-backed reports, inspect records, and export board-ready files.</p>
                        </div>
                        <Button
                            v-if="platform.user.permissions.includes('reports.create')"
                            label="Create report"
                            icon="pi pi-plus"
                            @click="openCreateReport"
                        />
                    </section>

                    <Message v-if="reportError" severity="error" closable @close="reportError = ''">
                        {{ reportError }}
                    </Message>

                    <section class="report-workspace">
                        <aside class="report-library">
                            <div class="report-library-heading">
                                <strong>Saved reports</strong><span>{{ reports.data.length }}</span>
                            </div>
                            <button
                                v-for="report in reports.data"
                                :key="report.id"
                                :class="['report-list-item', { active: activeReport?.id === report.id }]"
                                type="button"
                                @click="openReport(report.id)"
                            >
                                <i class="pi pi-chart-bar"></i>
                                <div>
                                    <strong>{{ report.name }}</strong>
                                    <span>{{ report.type_label }} · {{ report.latest_snapshot?.row_count ?? 0 }} rows</span>
                                </div>
                            </button>
                        </aside>

                        <article v-if="activeReport" class="report-detail">
                            <header class="report-detail-header">
                                <div>
                                    <div class="report-badges">
                                        <Tag severity="info" :value="activeReport.type_label" />
                                        <Tag severity="secondary" :value="activeReport.visibility" icon="pi pi-eye" />
                                    </div>
                                    <h2>{{ activeReport.name }}</h2>
                                    <p>{{ activeReport.description }}</p>
                                </div>
                                <div class="report-actions">
                                    <Button
                                        v-if="platform.user.permissions.includes('reports.create')"
                                        label="Configure"
                                        icon="pi pi-cog"
                                        severity="secondary"
                                        outlined
                                        @click="openEditReport(activeReport)"
                                    />
                                    <Button
                                        v-if="platform.user.permissions.includes('reports.create')"
                                        label="Refresh data"
                                        icon="pi pi-refresh"
                                        :loading="reportLoading"
                                        @click="generateReport(activeReport)"
                                    />
                                </div>
                            </header>

                            <div class="report-filter-row">
                                <InputText v-model="reportFilters.date_from" type="date" aria-label="Start date" />
                                <InputText v-model="reportFilters.date_to" type="date" aria-label="End date" />
                                <InputText v-model="reportFilters.region" placeholder="Region" aria-label="Region" />
                                <Button label="Apply filters" icon="pi pi-filter" outlined @click="openReport(activeReport.id)" />
                                <div class="export-actions">
                                    <Button icon="pi pi-file-excel" label="Excel" severity="success" outlined :disabled="!activeReport.latest_snapshot" @click="exportReport(activeReport, 'xlsx')" />
                                    <Button icon="pi pi-file-pdf" label="PDF" severity="danger" outlined :disabled="!activeReport.latest_snapshot" @click="exportReport(activeReport, 'pdf')" />
                                </div>
                            </div>

                            <div class="report-visual">
                                <div class="report-chart">
                                    <apexchart
                                        :type="activeReport.definition.chart?.type || 'bar'"
                                        height="290"
                                        :options="reportChartOptions(activeReport)"
                                        :series="reportChartSeries(activeReport)"
                                    />
                                </div>
                                <div class="report-state">
                                    <span>Last generated</span>
                                    <strong>{{ activeReport.last_generated_at ? new Date(activeReport.last_generated_at).toLocaleString() : 'Not generated' }}</strong>
                                    <small v-if="activeReport.citations?.length">
                                        Source: {{ activeReport.citations[0].source_name }}
                                    </small>
                                    <small v-else>Choose a connected source in Configure.</small>
                                </div>
                            </div>

                            <div class="report-table-wrap">
                                <table class="report-table">
                                    <thead>
                                        <tr>
                                            <th v-for="column in activeReport.definition.columns" :key="column.key">{{ column.label }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, index) in activeReport.rows" :key="index">
                                            <td v-for="column in activeReport.definition.columns" :key="column.key">
                                                {{ formatCell(row[column.key], column.type) }}
                                            </td>
                                        </tr>
                                        <tr v-if="!activeReport.rows?.length">
                                            <td :colspan="activeReport.definition.columns.length">
                                                <div class="table-empty">
                                                    <i class="pi pi-database"></i>
                                                    <strong>No report snapshot yet</strong>
                                                    <span>Configure a connected source, then refresh this report.</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    </section>
                </template>

                <template v-else-if="currentView === 'schedules'">
                    <section class="welcome-row intelligence-heading">
                        <div>
                            <p class="eyebrow">Automated, governed delivery</p>
                            <h1>Scheduled reports</h1>
                            <p>Deliver refreshed reports by email or Microsoft Teams on an approved timetable.</p>
                        </div>
                        <Button label="Create schedule" icon="pi pi-plus" @click="openCreateSchedule" />
                    </section>

                    <Message v-if="scheduleError" severity="error" closable @close="scheduleError = ''">
                        {{ scheduleError }}
                    </Message>

                    <section class="schedule-summary">
                        <article>
                            <i class="pi pi-calendar-clock"></i>
                            <div><strong>{{ schedules.data.length }}</strong><span>Total schedules</span></div>
                        </article>
                        <article>
                            <i class="pi pi-play-circle"></i>
                            <div><strong>{{ schedules.data.filter((schedule) => schedule.is_active).length }}</strong><span>Active schedules</span></div>
                        </article>
                        <article>
                            <i class="pi pi-send"></i>
                            <div><strong>{{ schedules.data.filter((schedule) => schedule.last_status === 'succeeded').length }}</strong><span>Healthy deliveries</span></div>
                        </article>
                    </section>

                    <div v-if="schedulesLoading && !schedules.data.length" class="source-empty panel">
                        <i class="pi pi-spin pi-spinner"></i><span>Loading scheduled reports…</span>
                    </div>

                    <section v-else-if="!schedules.data.length" class="source-empty panel">
                        <div class="empty-icon"><i class="pi pi-calendar-plus"></i></div>
                        <h3>No schedules configured</h3>
                        <p>Create a source-backed report, then configure its delivery timetable.</p>
                        <Button label="Create first schedule" icon="pi pi-plus" outlined @click="openCreateSchedule" />
                    </section>

                    <section v-else class="schedule-grid">
                        <article v-for="schedule in schedules.data" :key="schedule.id" class="panel schedule-card">
                            <header>
                                <div class="schedule-icon"><i class="pi pi-file-export"></i></div>
                                <div>
                                    <span>{{ schedule.frequency }} · {{ schedule.format.toUpperCase() }}</span>
                                    <h2>{{ schedule.report.name }}</h2>
                                </div>
                                <Tag :severity="schedule.is_active ? 'success' : 'secondary'" :value="schedule.is_active ? 'Active' : 'Paused'" />
                            </header>
                            <div class="schedule-timeline">
                                <div>
                                    <span>Next delivery</span>
                                    <strong>{{ schedule.next_run_at ? new Date(schedule.next_run_at).toLocaleString() : 'Paused' }}</strong>
                                </div>
                                <div>
                                    <span>Last result</span>
                                    <Tag :severity="scheduleSeverity(schedule.last_status)" :value="schedule.last_status || 'Not run'" />
                                </div>
                            </div>
                            <div class="schedule-channels">
                                <span v-for="channel in schedule.delivery_channels" :key="channel">
                                    <i :class="['pi', channel === 'email' ? 'pi-envelope' : 'pi-microsoft']"></i>{{ channel }}
                                </span>
                                <small>{{ schedule.timezone }} · {{ schedule.cron_expression }}</small>
                            </div>
                            <div v-if="schedule.last_error" class="schedule-error">
                                <i class="pi pi-exclamation-triangle"></i>{{ schedule.last_error }}
                            </div>
                            <div class="schedule-actions">
                                <Button label="Run now" icon="pi pi-play" size="small" outlined :loading="runningScheduleId === schedule.id" @click="runSchedule(schedule)" />
                                <Button label="Configure" icon="pi pi-cog" size="small" severity="secondary" text @click="openEditSchedule(schedule)" />
                                <Button icon="pi pi-trash" size="small" severity="danger" text rounded aria-label="Remove schedule" @click="removeSchedule(schedule)" />
                            </div>
                            <div v-if="schedule.runs?.length" class="run-history">
                                <span>Recent runs</span>
                                <div v-for="run in schedule.runs.slice(0, 3)" :key="run.id">
                                    <i :class="['pi', run.status === 'succeeded' ? 'pi-check-circle' : run.status === 'failed' ? 'pi-times-circle' : 'pi-clock']"></i>
                                    <strong>{{ run.trigger }}</strong>
                                    <small>{{ run.status }} · {{ new Date(run.created_at).toLocaleString() }}</small>
                                </div>
                            </div>
                        </article>
                    </section>
                </template>

                <template v-else-if="currentView === 'analytics'">
                    <section class="welcome-row intelligence-heading">
                        <div>
                            <p class="eyebrow">Governed statistical intelligence</p>
                            <h1>Advanced analytics</h1>
                            <p>Detect unusual values, measure trends, project near-term outcomes, and surface actions from approved report snapshots.</p>
                        </div>
                        <div class="analytics-run">
                            <Select
                                v-model="analyticsReportId"
                                :options="analytics.reports"
                                option-label="name"
                                option-value="id"
                                placeholder="Choose a generated report"
                                aria-label="Analytics report"
                            />
                            <Button
                                label="Run analysis"
                                icon="pi pi-sparkles"
                                :disabled="!analyticsReportId || !platform.user.permissions.includes('analytics.run')"
                                :loading="generatingAnalyticsId !== null"
                                @click="generateAnalytics"
                            />
                        </div>
                    </section>

                    <Message v-if="analyticsError" severity="error" closable @close="analyticsError = ''">
                        {{ analyticsError }}
                    </Message>

                    <section class="analytics-summary">
                        <article><i class="pi pi-sparkles"></i><div><strong>{{ analytics.summary.total }}</strong><span>Total insights</span></div></article>
                        <article><i class="pi pi-exclamation-triangle"></i><div><strong>{{ analytics.summary.anomalies }}</strong><span>Anomalies</span></div></article>
                        <article><i class="pi pi-forward"></i><div><strong>{{ analytics.summary.forecasts }}</strong><span>Forecasts</span></div></article>
                        <article><i class="pi pi-lightbulb"></i><div><strong>{{ analytics.summary.actions }}</strong><span>Recommended actions</span></div></article>
                    </section>

                    <section v-if="analyticsLoading && !analytics.data.length" class="source-empty panel">
                        <i class="pi pi-spin pi-spinner"></i><span>Loading advanced analytics…</span>
                    </section>

                    <section v-else-if="!analytics.reports.length" class="source-empty panel">
                        <div class="empty-icon"><i class="pi pi-chart-line"></i></div>
                        <h3>No eligible report snapshots</h3>
                        <p>Generate a report with at least three rows and one numeric metric before running analytics.</p>
                        <Button label="Open reports" icon="pi pi-arrow-right" outlined @click="setView('reports')" />
                    </section>

                    <section v-else-if="!analytics.data.length" class="source-empty panel">
                        <div class="empty-icon"><i class="pi pi-sparkles"></i></div>
                        <h3>Ready for analysis</h3>
                        <p>Select a generated report and run the governed statistical engine.</p>
                        <Button
                            v-if="platform.user.permissions.includes('analytics.run')"
                            label="Run first analysis"
                            icon="pi pi-sparkles"
                            outlined
                            :loading="generatingAnalyticsId !== null"
                            @click="generateAnalytics"
                        />
                    </section>

                    <section v-else class="analytics-grid">
                        <article v-for="insight in analytics.data" :key="insight.id" class="panel insight-card">
                            <header>
                                <div :class="['insight-icon', `insight-${insight.severity}`]">
                                    <i :class="['pi', insightIcon(insight.type)]"></i>
                                </div>
                                <div>
                                    <span>{{ insight.report?.name }}</span>
                                    <h2>{{ insight.title }}</h2>
                                </div>
                                <Tag :severity="insightSeverity(insight.severity)" :value="insight.type" />
                            </header>
                            <p>{{ insight.narrative }}</p>
                            <div v-if="insight.type === 'trend'" class="insight-values">
                                <span>Direction <strong>{{ insight.payload.direction }}</strong></span>
                                <span>Change <strong>{{ insight.payload.change_percent ?? 'N/A' }}{{ insight.payload.change_percent !== null ? '%' : '' }}</strong></span>
                            </div>
                            <div v-else-if="insight.type === 'forecast'" class="forecast-values">
                                <span v-for="(value, index) in insight.payload.values" :key="index">
                                    P{{ index + 1 }} <strong>{{ Number(value).toLocaleString() }}</strong>
                                </span>
                            </div>
                            <div v-else-if="insight.type === 'anomaly'" class="insight-values">
                                <span>Observed <strong>{{ Number(insight.payload.value).toLocaleString() }}</strong></span>
                                <span>Score <strong>{{ insight.payload.score }}</strong></span>
                            </div>
                            <footer>
                                <span>{{ insight.metric_key }}</span>
                                <time>{{ new Date(insight.generated_at).toLocaleString() }}</time>
                            </footer>
                        </article>
                    </section>
                </template>

                <template v-else-if="currentView === 'integrations'">
                    <section class="welcome-row integration-heading">
                        <div>
                            <p class="eyebrow">Integration control center</p>
                            <h1>Enterprise data sources</h1>
                            <p>Configure approved systems, verify access, and monitor connection health.</p>
                        </div>
                        <Button label="Add data source" icon="pi pi-plus" @click="openCreateSource" />
                    </section>

                    <Message v-if="integrationError" severity="error" :closable="true" @close="integrationError = ''">
                        {{ integrationError }}
                    </Message>

                    <section class="integration-summary">
                        <article>
                            <i class="pi pi-database"></i>
                            <div><strong>{{ integrations.data.length }}</strong><span>Configured sources</span></div>
                        </article>
                        <article>
                            <i class="pi pi-check-circle"></i>
                            <div>
                                <strong>{{ integrations.data.filter((source) => source.status === 'connected').length }}</strong>
                                <span>Healthy connections</span>
                            </div>
                        </article>
                        <article>
                            <i class="pi pi-shield"></i>
                            <div>
                                <strong>{{ integrations.data.filter((source) => source.has_credentials).length }}</strong>
                                <span>Encrypted credentials</span>
                            </div>
                        </article>
                    </section>

                    <section class="panel sources-panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Source registry</p>
                                <h2>Connected systems</h2>
                            </div>
                            <span class="muted">Secrets are never returned to the browser</span>
                        </div>

                        <div v-if="integrationsLoading" class="source-empty">
                            <i class="pi pi-spin pi-spinner"></i>
                            <span>Loading integration registry…</span>
                        </div>

                        <div v-else-if="!integrations.data.length" class="source-empty">
                            <div class="empty-icon"><i class="pi pi-link"></i></div>
                            <h3>No data sources configured</h3>
                            <p>Add the first approved CRM, ERP, SAP, or internal application endpoint.</p>
                            <Button label="Add first source" icon="pi pi-plus" outlined @click="openCreateSource" />
                        </div>

                        <div v-else class="source-list">
                            <article v-for="source in integrations.data" :key="source.id" class="source-card">
                                <div class="source-type-icon">
                                    <i :class="['pi', source.type_icon]"></i>
                                </div>
                                <div class="source-main">
                                    <div class="source-title">
                                        <div>
                                            <strong>{{ source.name }}</strong>
                                            <span>{{ source.type_label }}</span>
                                        </div>
                                        <Tag :severity="statusSeverity(source.status)" :value="source.status" />
                                    </div>
                                    <p>{{ source.description || 'No source description provided.' }}</p>
                                    <div class="source-meta">
                                        <span>
                                            <i class="pi pi-globe"></i>
                                            {{ source.type === 'google_search_console' ? source.settings.site_url : source.base_url }}
                                        </span>
                                        <span>
                                            <i class="pi pi-lock"></i>
                                            {{
                                                source.type === 'google_search_console'
                                                    ? 'Server-side service account'
                                                    : source.auth_type === 'none'
                                                        ? 'No authentication'
                                                        : `${source.auth_type} authentication`
                                            }}
                                        </span>
                                        <span v-if="source.latest_run">
                                            <i class="pi pi-bolt"></i>
                                            {{ source.latest_run.duration_ms }} ms
                                        </span>
                                    </div>
                                    <div v-if="source.latest_run" class="run-result">
                                        <i :class="['pi', source.latest_run.status === 'succeeded' ? 'pi-check-circle' : 'pi-exclamation-circle']"></i>
                                        <span>{{ source.latest_run.message }}</span>
                                        <small v-if="source.latest_run.http_status">HTTP {{ source.latest_run.http_status }}</small>
                                    </div>
                                </div>
                                <div class="source-actions">
                                    <Button
                                        label="Test"
                                        icon="pi pi-bolt"
                                        size="small"
                                        outlined
                                        :loading="testingSourceId === source.id"
                                        @click="testSource(source)"
                                    />
                                    <Button
                                        v-if="source.type === 'google_search_console'"
                                        label="Preview"
                                        icon="pi pi-chart-line"
                                        size="small"
                                        outlined
                                        :disabled="source.status !== 'connected'"
                                        :loading="previewingSourceId === source.id"
                                        @click="previewSource(source)"
                                    />
                                    <Button icon="pi pi-pencil" size="small" severity="secondary" text rounded aria-label="Edit source" @click="openEditSource(source)" />
                                    <Button icon="pi pi-trash" size="small" severity="danger" text rounded aria-label="Remove source" @click="removeSource(source)" />
                                </div>
                            </article>
                        </div>
                    </section>
                </template>

                <template v-else-if="currentView === 'users'">
                    <section class="welcome-row intelligence-heading">
                        <div>
                            <p class="eyebrow">Identity and authorization</p>
                            <h1>Users & access</h1>
                            <p>Assign roles, departments, job titles, and account activation status.</p>
                        </div>
                        <div class="users-heading-actions">
                            <Tag severity="info" :value="`${adminUsers.meta.total} users`" icon="pi pi-users" />
                            <Button
                                v-if="platform.user.permissions.includes('users.manage')"
                                label="Add user"
                                icon="pi pi-user-plus"
                                @click="openCreateUser"
                            />
                        </div>
                    </section>

                    <Message v-if="adminUsersError" severity="error" closable @close="adminUsersError = ''">
                        {{ adminUsersError }}
                    </Message>

                    <section class="panel admin-panel">
                        <div class="admin-toolbar">
                            <div>
                                <InputText
                                    v-model="adminUserSearch"
                                    placeholder="Search name, email, department, or title"
                                    aria-label="Search users"
                                    @keydown.enter="loadAdminUsers(1)"
                                />
                                <Button label="Search" icon="pi pi-search" outlined @click="loadAdminUsers(1)" />
                            </div>
                            <span>Departments are assigned to each user and control departmental visibility.</span>
                        </div>

                        <div v-if="adminUsersLoading" class="source-empty">
                            <i class="pi pi-spin pi-spinner"></i><span>Loading users…</span>
                        </div>

                        <div v-else class="report-table-wrap">
                            <table class="report-table admin-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Department</th>
                                        <th>Title</th>
                                        <th>Roles</th>
                                        <th>Status</th>
                                        <th>Last login</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="user in adminUsers.data" :key="user.id">
                                        <td>
                                            <div class="user-cell">
                                                <Avatar :label="user.name.charAt(0)" shape="circle" />
                                                <div><strong>{{ user.name }}</strong><span>{{ user.email }}</span></div>
                                            </div>
                                        </td>
                                        <td>{{ user.department || 'Unassigned' }}</td>
                                        <td>{{ user.title || '—' }}</td>
                                        <td>
                                            <div class="role-tags">
                                                <Tag
                                                    v-for="role in user.roles"
                                                    :key="role.name"
                                                    severity="secondary"
                                                    :value="role.label"
                                                />
                                            </div>
                                        </td>
                                        <td>
                                            <div class="status-stack">
                                                <Tag :severity="user.is_active ? 'success' : 'danger'" :value="user.is_active ? 'Active' : 'Inactive'" />
                                                <Tag
                                                    v-if="user.must_change_password"
                                                    severity="warn"
                                                    value="Password pending"
                                                />
                                                <Tag
                                                    v-if="user.two_factor_enabled === false"
                                                    severity="warn"
                                                    value="No 2FA"
                                                />
                                            </div>
                                        </td>
                                        <td>{{ user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Never' }}</td>
                                        <td>
                                            <Button
                                                v-if="platform.user.permissions.includes('users.manage')"
                                                icon="pi pi-pencil"
                                                text
                                                rounded
                                                aria-label="Edit user access"
                                                @click="openUserAccess(user)"
                                            />
                                        </td>
                                    </tr>
                                    <tr v-if="!adminUsers.data.length">
                                        <td colspan="7"><div class="table-empty">No users match the current search.</div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="admin-pagination">
                            <Button
                                label="Previous"
                                icon="pi pi-angle-left"
                                outlined
                                :disabled="adminUsers.meta.current_page <= 1"
                                @click="loadAdminUsers(adminUsers.meta.current_page - 1)"
                            />
                            <span>Page {{ adminUsers.meta.current_page }} of {{ adminUsers.meta.last_page }}</span>
                            <Button
                                label="Next"
                                icon="pi pi-angle-right"
                                icon-pos="right"
                                outlined
                                :disabled="adminUsers.meta.current_page >= adminUsers.meta.last_page"
                                @click="loadAdminUsers(adminUsers.meta.current_page + 1)"
                            />
                        </div>
                    </section>
                </template>

                <template v-else-if="currentView === 'audit'">
                    <section class="welcome-row intelligence-heading">
                        <div>
                            <p class="eyebrow">Governance evidence</p>
                            <h1>Audit trail</h1>
                            <p>Review bounded authentication, administration, integration, reporting, and delivery events.</p>
                        </div>
                        <Tag severity="info" :value="`${auditTrail.meta.total} events`" icon="pi pi-shield" />
                    </section>

                    <Message v-if="auditError" severity="error" closable @close="auditError = ''">
                        {{ auditError }}
                    </Message>

                    <section class="panel admin-panel">
                        <div class="audit-filters">
                            <InputText v-model="auditFilters.event" placeholder="Filter event name" aria-label="Audit event filter" />
                            <InputText v-model="auditFilters.date_from" type="date" aria-label="Audit start date" />
                            <InputText v-model="auditFilters.date_to" type="date" aria-label="Audit end date" />
                            <Button label="Apply" icon="pi pi-filter" outlined @click="loadAuditTrail(1)" />
                        </div>

                        <div v-if="auditLoading" class="source-empty">
                            <i class="pi pi-spin pi-spinner"></i><span>Loading audit events…</span>
                        </div>

                        <div v-else class="report-table-wrap">
                            <table class="report-table admin-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Event</th>
                                        <th>Actor</th>
                                        <th>Target</th>
                                        <th>IP address</th>
                                        <th>Evidence</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="event in auditTrail.data" :key="event.id">
                                        <td>{{ new Date(event.created_at).toLocaleString() }}</td>
                                        <td><Tag severity="secondary" :value="event.event" /></td>
                                        <td>
                                            <div class="audit-actor">
                                                <strong>{{ event.actor?.name || 'System / unknown' }}</strong>
                                                <span v-if="event.actor">{{ event.actor.email }}</span>
                                            </div>
                                        </td>
                                        <td>{{ event.auditable_type || '—' }}<span v-if="event.auditable_id"> #{{ event.auditable_id }}</span></td>
                                        <td>{{ event.ip_address || '—' }}</td>
                                        <td><code>{{ JSON.stringify(event.metadata || {}) }}</code></td>
                                    </tr>
                                    <tr v-if="!auditTrail.data.length">
                                        <td colspan="6"><div class="table-empty">No audit events match the current filters.</div></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="admin-pagination">
                            <Button
                                label="Previous"
                                icon="pi pi-angle-left"
                                outlined
                                :disabled="auditTrail.meta.current_page <= 1"
                                @click="loadAuditTrail(auditTrail.meta.current_page - 1)"
                            />
                            <span>Page {{ auditTrail.meta.current_page }} of {{ auditTrail.meta.last_page }}</span>
                            <Button
                                label="Next"
                                icon="pi pi-angle-right"
                                icon-pos="right"
                                outlined
                                :disabled="auditTrail.meta.current_page >= auditTrail.meta.last_page"
                                @click="loadAuditTrail(auditTrail.meta.current_page + 1)"
                            />
                        </div>
                    </section>
                </template>
            </div>

            <Dialog
                v-model:visible="userAccessDialogOpen"
                modal
                header="Edit user access"
                :style="{ width: 'min(620px, 94vw)' }"
                :draggable="false"
            >
                <form id="user-access-form" class="source-form" @submit.prevent="saveUserAccess">
                    <Message v-if="adminUsersError" severity="error" :closable="false">
                        {{ adminUsersError }}
                    </Message>

                    <div class="form-grid">
                        <div class="field">
                            <label for="access-name">Full name</label>
                            <InputText id="access-name" v-model="userAccessForm.name" required fluid />
                        </div>
                        <div class="field">
                            <label for="access-email">Email</label>
                            <InputText id="access-email" v-model="userAccessForm.email" type="email" required fluid />
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="access-department">Department</label>
                            <InputText
                                id="access-department"
                                v-model="userAccessForm.department"
                                list="department-options"
                                placeholder="Information Technology"
                                fluid
                            />
                            <datalist id="department-options">
                                <option v-for="department in adminUsers.departments" :key="department" :value="department"></option>
                            </datalist>
                            <small>Enter an existing or new department. This controls department-scoped dashboards, reports, and sources.</small>
                        </div>
                        <div class="field">
                            <label for="access-title">Job title</label>
                            <InputText id="access-title" v-model="userAccessForm.title" placeholder="Service Desk Manager" fluid />
                        </div>
                    </div>

                    <div class="field">
                        <label for="access-roles">Roles</label>
                        <MultiSelect
                            id="access-roles"
                            v-model="userAccessForm.roles"
                            :options="adminUsers.roles"
                            option-label="label"
                            option-value="name"
                            display="chip"
                            required
                            fluid
                        />
                    </div>

                    <div class="field">
                        <label for="access-allowed-departments">Departments this user may view</label>
                        <MultiSelect
                            id="access-allowed-departments"
                            v-model="userAccessForm.allowed_departments"
                            :options="adminUsers.departments"
                            display="chip"
                            filter
                            fluid
                        />
                        <small>
                            Department-scoped dashboards and reports are shown only for these
                            departments. Leave empty to fall back to the department above.
                            Roles still decide which features the user can open.
                        </small>
                    </div>

                    <label class="schedule-active-control">
                        <input v-model="userAccessForm.restrict_data_sources" type="checkbox" />
                        <span>
                            <strong>Restrict to specific platforms</strong>
                            <small>
                                When off, platform access follows the authorized roles and
                                departments configured on each data source.
                            </small>
                        </span>
                    </label>

                    <div v-if="userAccessForm.restrict_data_sources" class="field">
                        <label for="access-allowed-sources">Platforms this user may view</label>
                        <MultiSelect
                            id="access-allowed-sources"
                            v-model="userAccessForm.allowed_data_source_ids"
                            :options="adminUsers.data_sources"
                            option-label="name"
                            option-value="id"
                            display="chip"
                            filter
                            fluid
                        />
                        <small>
                            Selecting none permits no platform. Administrators and the owner of
                            a data source are unaffected by this restriction.
                        </small>
                        <Message v-if="inertSelectedPlatforms.length" severity="warn" :closable="false">
                            <strong>
                                {{ inertSelectedPlatforms.length === 1 ? 'This platform authorizes' : 'These platforms authorize' }}
                                no role or department, so selecting
                                {{ inertSelectedPlatforms.length === 1 ? 'it' : 'them' }} here has no effect:
                            </strong>
                            {{ inertSelectedPlatforms.map((source) => source.name).join(', ') }}.
                            Granting a platform narrows an audience the platform already defines - it
                            does not create one. Set Authorized roles or Authorized departments on
                            {{ inertSelectedPlatforms.length === 1 ? 'it' : 'each' }} under
                            Administration &rarr; Data sources, otherwise only its owner and
                            administrators can see it.
                        </Message>
                    </div>

                    <label class="schedule-active-control">
                        <input v-model="userAccessForm.is_active" type="checkbox" />
                        <span>
                            <strong>Active account</strong>
                            <small>Inactive users are denied login and lose existing sessions on their next request.</small>
                        </span>
                    </label>
                </form>

                <template #footer>
                    <Button label="Cancel" severity="secondary" text @click="userAccessDialogOpen = false" />
                    <Button
                        form="user-access-form"
                        type="submit"
                        label="Save access"
                        icon="pi pi-check"
                        :loading="userAccessSaving"
                    />
                </template>
            </Dialog>

            <Dialog
                v-model:visible="sourceDialogOpen"
                modal
                :header="editingSourceId ? 'Edit data source' : 'Add data source'"
                :style="{ width: 'min(680px, 94vw)' }"
                :draggable="false"
            >
                <form id="source-form" class="source-form" @submit.prevent="saveSource">
                    <Message v-if="integrationError" severity="error" :closable="false">
                        {{ integrationError }}
                    </Message>

                    <div class="form-grid">
                        <div class="field">
                            <label for="source-name">Source name</label>
                            <InputText id="source-name" v-model="sourceForm.name" placeholder="Regional CRM" required fluid />
                        </div>
                        <div class="field">
                            <label for="source-type">System type</label>
                            <Select
                                id="source-type"
                                v-model="sourceForm.type"
                                :options="integrations.types"
                                option-label="label"
                                option-value="value"
                                fluid
                                @change="applySourceType"
                            />
                        </div>
                    </div>

                    <div class="field">
                        <label for="source-description">Business purpose</label>
                        <Textarea id="source-description" v-model="sourceForm.description" rows="2" fluid />
                    </div>

                    <div v-if="sourceForm.type !== 'google_search_console'" class="field">
                        <label for="source-url">Base API URL</label>
                        <InputText
                            id="source-url"
                            v-model="sourceForm.base_url"
                            type="url"
                            placeholder="https://api.example.com"
                            required
                            fluid
                        />
                        <small>HTTPS is required unless private-network integrations are explicitly enabled.</small>
                    </div>
                    <Message v-else severity="info" :closable="false">
                        Ask GAHolding calls Google directly. No endpoint or code is required on aboudcar.com.
                    </Message>

                    <div v-if="sourceForm.type === 'google_search_console'" class="field">
                        <label for="search-console-site">Search Console property</label>
                        <InputText
                            id="search-console-site"
                            v-model="sourceForm.settings.site_url"
                            placeholder="https://www.example.com/ or sc-domain:example.com"
                            required
                            fluid
                        />
                        <small>This must exactly match the property granted to the service account.</small>
                    </div>

                    <div v-else class="form-grid">
                        <div class="field">
                            <label for="health-path">Health endpoint</label>
                            <InputText id="health-path" v-model="sourceForm.settings.health_path" placeholder="/health" fluid />
                        </div>
                        <div class="field">
                            <label for="data-path">Primary data endpoint</label>
                            <InputText id="data-path" v-model="sourceForm.settings.data_path" placeholder="/api/v1/reports" fluid />
                        </div>
                    </div>

                    <div v-if="sourceForm.type === 'freshservice'" class="field">
                        <label for="freshservice-on-hold-statuses">On-hold status IDs</label>
                        <InputText
                            id="freshservice-on-hold-statuses"
                            v-model="sourceForm.on_hold_status_ids_text"
                            placeholder="3, 8"
                            fluid
                        />
                        <small>Comma-separated Freshservice statuses whose SLA timer is disabled. These are excluded from overdue and due-today counts.</small>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="allowed-roles">Authorized roles</label>
                            <MultiSelect
                                id="allowed-roles"
                                v-model="sourceForm.settings.allowed_roles"
                                :options="[
                                    { label: 'Executives', value: 'executive' },
                                    { label: 'Managers', value: 'manager' },
                                    { label: 'Analysts', value: 'analyst' },
                                ]"
                                option-label="label"
                                option-value="value"
                                placeholder="Administrators and owner only"
                                display="chip"
                                fluid
                            />
                        </div>
                        <div class="field">
                            <label for="allowed-departments">Authorized departments</label>
                            <InputText
                                id="allowed-departments"
                                v-model="sourceForm.allowed_departments_text"
                                placeholder="Finance, Sales"
                                fluid
                            />
                            <small>Comma-separated. Access is allowed when either role or department matches.</small>
                        </div>
                    </div>

                    <div v-if="sourceForm.type !== 'google_search_console'" class="field">
                        <label for="auth-type">Authentication</label>
                        <Select
                            id="auth-type"
                            v-model="sourceForm.auth_type"
                            :options="integrations.auth_types"
                            option-label="label"
                            option-value="value"
                            fluid
                        />
                    </div>
                    <Message v-else severity="info" :closable="false">
                        Authentication uses the server-side service-account file. No credential is stored in this data-source record.
                    </Message>

                    <div v-if="sourceForm.auth_type === 'bearer'" class="field secret-field">
                        <label for="bearer-token">Bearer token</label>
                        <Password id="bearer-token" v-model="sourceForm.credentials.token" :feedback="false" toggle-mask fluid />
                        <small v-if="editingSourceId">Leave blank to preserve the currently encrypted token.</small>
                    </div>

                    <div v-if="sourceForm.auth_type === 'api_key'" class="form-grid secret-field">
                        <div class="field">
                            <label for="api-header">Header name</label>
                            <InputText id="api-header" v-model="sourceForm.credentials.header" fluid />
                        </div>
                        <div class="field">
                            <label for="api-key">API key</label>
                            <Password id="api-key" v-model="sourceForm.credentials.api_key" :feedback="false" toggle-mask fluid />
                            <small v-if="editingSourceId">Leave blank to preserve the current key.</small>
                        </div>
                    </div>

                    <div v-if="sourceForm.auth_type === 'basic'" class="form-grid secret-field">
                        <div class="field">
                            <label for="basic-user">{{ sourceForm.type === 'freshservice' ? 'Freshservice API key' : 'Username' }}</label>
                            <InputText id="basic-user" v-model="sourceForm.credentials.username" autocomplete="off" fluid />
                            <small v-if="sourceForm.type === 'freshservice'">Use the API key from your Freshservice agent profile.</small>
                        </div>
                        <div class="field">
                            <label for="basic-password">{{ sourceForm.type === 'freshservice' ? 'Basic-auth placeholder' : 'Password' }}</label>
                            <Password id="basic-password" v-model="sourceForm.credentials.password" :feedback="false" toggle-mask fluid />
                            <small v-if="sourceForm.type === 'freshservice'">Freshservice expects any placeholder such as X.</small>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="timeout">Timeout (seconds)</label>
                            <InputNumber id="timeout" v-model="sourceForm.timeout_seconds" :min="1" :max="60" fluid />
                        </div>
                        <div class="field">
                            <label for="retries">Retry attempts</label>
                            <InputNumber id="retries" v-model="sourceForm.retry_count" :min="0" :max="5" fluid />
                        </div>
                    </div>
                </form>

                <template #footer>
                    <Button label="Cancel" severity="secondary" text @click="sourceDialogOpen = false" />
                    <Button
                        form="source-form"
                        type="submit"
                        :label="editingSourceId ? 'Save changes' : 'Add source'"
                        icon="pi pi-check"
                        :loading="sourceSaving"
                    />
                </template>
            </Dialog>

            <Dialog
                v-model:visible="previewDialogOpen"
                modal
                header="Google Search Console data"
                :style="{ width: 'min(1040px, 96vw)' }"
                :draggable="false"
            >
                <Message v-if="previewError" severity="error" :closable="false">{{ previewError }}</Message>

                <template v-if="searchConsolePreview">
                    <div class="search-console-filters">
                        <InputText v-model="previewFilters.date_from" type="date" aria-label="Start date" />
                        <InputText v-model="previewFilters.date_to" type="date" aria-label="End date" />
                        <Select
                            v-model="previewFilters.dimension"
                            :options="[
                                { label: 'Queries', value: 'query' },
                                { label: 'Pages', value: 'page' },
                                { label: 'Countries', value: 'country' },
                                { label: 'Devices', value: 'device' },
                                { label: 'Dates', value: 'date' },
                            ]"
                            option-label="label"
                            option-value="value"
                            aria-label="Breakdown"
                        />
                        <Button
                            label="Refresh"
                            icon="pi pi-refresh"
                            :loading="previewingSourceId === searchConsolePreview.citation.source_id"
                            @click="previewSource({ id: searchConsolePreview.citation.source_id })"
                        />
                    </div>

                    <section class="search-console-summary">
                        <article><span>Clicks</span><strong>{{ Number(searchConsolePreview.summary.clicks).toLocaleString() }}</strong></article>
                        <article><span>Impressions</span><strong>{{ Number(searchConsolePreview.summary.impressions).toLocaleString() }}</strong></article>
                        <article><span>CTR</span><strong>{{ searchConsolePreview.summary.ctr }}%</strong></article>
                        <article><span>Avg. position</span><strong>{{ searchConsolePreview.summary.position }}</strong></article>
                    </section>

                    <p class="search-console-context">
                        {{ searchConsolePreview.summary.site_url }} ·
                        {{ searchConsolePreview.summary.date_from }} to {{ searchConsolePreview.summary.date_to }}
                    </p>

                    <div class="report-table-wrap">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>{{ searchConsolePreview.summary.dimension }}</th>
                                    <th>Clicks</th>
                                    <th>Impressions</th>
                                    <th>CTR</th>
                                    <th>Avg. position</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, index) in searchConsolePreview.rows" :key="index">
                                    <td>{{ row[searchConsolePreview.summary.dimension] }}</td>
                                    <td>{{ Number(row.clicks).toLocaleString() }}</td>
                                    <td>{{ Number(row.impressions).toLocaleString() }}</td>
                                    <td>{{ row.ctr }}%</td>
                                    <td>{{ row.position }}</td>
                                </tr>
                                <tr v-if="!searchConsolePreview.rows.length">
                                    <td colspan="5">
                                        <div class="table-empty">
                                            <i class="pi pi-search"></i>
                                            <strong>No Search Console rows returned</strong>
                                            <span>Try a wider date range or another breakdown.</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </Dialog>

            <Dialog
                v-model:visible="reportDialogOpen"
                modal
                :header="editingReportId ? 'Configure report' : 'Create report'"
                :style="{ width: 'min(760px, 95vw)' }"
                :draggable="false"
            >
                <form id="report-form" class="source-form" @submit.prevent="saveReport">
                    <Message v-if="reportError" severity="error" :closable="false">{{ reportError }}</Message>

                    <div class="form-grid">
                        <div class="field">
                            <label for="report-name">Report name</label>
                            <InputText id="report-name" v-model="reportForm.name" required fluid />
                        </div>
                        <div class="field">
                            <label for="report-type">Report type</label>
                            <Select
                                id="report-type"
                                v-model="reportForm.type"
                                :options="reports.types"
                                option-label="label"
                                option-value="value"
                                fluid
                            />
                        </div>
                    </div>

                    <div class="field">
                        <label for="report-description">Business purpose</label>
                        <Textarea id="report-description" v-model="reportForm.description" rows="2" fluid />
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="report-source">Connected source</label>
                            <Select
                                id="report-source"
                                v-model="reportForm.definition.source_id"
                                :options="reports.sources"
                                option-label="name"
                                option-value="id"
                                placeholder="Select after connection test"
                                show-clear
                                fluid
                                @change="applyReportSource"
                            />
                        </div>
                        <div class="field">
                            <label for="report-visibility">Visibility</label>
                            <Select
                                id="report-visibility"
                                v-model="reportForm.visibility"
                                :options="[
                                    { label: 'Only me', value: 'private' },
                                    { label: 'My department', value: 'department' },
                                    ...(platform.user.permissions.includes('reports.publish')
                                        ? [{ label: 'Enterprise', value: 'enterprise' }]
                                        : []),
                                ]"
                                option-label="label"
                                option-value="value"
                                fluid
                            />
                        </div>
                    </div>

                    <div v-if="selectedReportSource?.type === 'google_search_console'" class="field">
                        <label for="search-console-report-dimension">Search analytics breakdown</label>
                        <Select
                            id="search-console-report-dimension"
                            v-model="reportForm.definition.search_console_dimension"
                            :options="[
                                { label: 'Search queries', value: 'query' },
                                { label: 'Landing pages', value: 'page' },
                                { label: 'Countries', value: 'country' },
                                { label: 'Devices', value: 'device' },
                                { label: 'Daily trend', value: 'date' },
                            ]"
                            option-label="label"
                            option-value="value"
                            fluid
                            @change="applySearchConsoleReportDimension"
                        />
                        <small>Columns and chart settings are prepared automatically for the selected breakdown.</small>
                    </div>

                    <div class="report-schema-heading">
                        <div><strong>Column schema</strong><span>Keys must match fields returned by the source API.</span></div>
                        <Button
                            label="Add column"
                            icon="pi pi-plus"
                            size="small"
                            text
                            type="button"
                            @click="reportForm.definition.columns.push({ key: '', label: '', type: 'text' })"
                        />
                    </div>
                    <div class="report-schema">
                        <div v-for="(column, index) in reportForm.definition.columns" :key="index" class="report-schema-row">
                            <InputText v-model="column.key" placeholder="API field key" required />
                            <InputText v-model="column.label" placeholder="Display label" required />
                            <Select
                                v-model="column.type"
                                :options="['text', 'number', 'currency', 'percentage', 'date']"
                            />
                            <Select
                                v-model="column.mask"
                                :options="[
                                    { label: 'Visible', value: null },
                                    { label: 'Mask email', value: 'email' },
                                    { label: 'Mask phone', value: 'phone' },
                                    { label: 'Keep last 4', value: 'last4' },
                                    { label: 'Redact', value: 'redact' },
                                ]"
                                option-label="label"
                                option-value="value"
                                placeholder="Visible"
                            />
                            <Button
                                icon="pi pi-trash"
                                severity="danger"
                                text
                                rounded
                                type="button"
                                aria-label="Remove column"
                                :disabled="reportForm.definition.columns.length === 1"
                                @click="reportForm.definition.columns.splice(index, 1)"
                            />
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="chart-type">Chart type</label>
                            <Select id="chart-type" v-model="reportForm.definition.chart.type" :options="['bar', 'line', 'area', 'donut']" fluid />
                        </div>
                        <div class="field">
                            <label for="chart-title">Chart title</label>
                            <InputText id="chart-title" v-model="reportForm.definition.chart.title" fluid />
                        </div>
                        <div class="field">
                            <label for="category-key">Category field</label>
                            <Select
                                id="category-key"
                                v-model="reportForm.definition.chart.category_key"
                                :options="reportForm.definition.columns"
                                option-label="label"
                                option-value="key"
                                fluid
                            />
                        </div>
                        <div class="field">
                            <label for="value-key">Value field</label>
                            <Select
                                id="value-key"
                                v-model="reportForm.definition.chart.value_key"
                                :options="reportForm.definition.columns"
                                option-label="label"
                                option-value="key"
                                fluid
                            />
                        </div>
                    </div>
                </form>

                <template #footer>
                    <Button label="Cancel" severity="secondary" text @click="reportDialogOpen = false" />
                    <Button
                        form="report-form"
                        type="submit"
                        :label="editingReportId ? 'Save configuration' : 'Create report'"
                        icon="pi pi-check"
                        :loading="reportSaving"
                    />
                </template>
            </Dialog>

            <Dialog
                v-model:visible="scheduleDialogOpen"
                modal
                :header="editingScheduleId ? 'Configure schedule' : 'Create schedule'"
                :style="{ width: 'min(700px, 95vw)' }"
                :draggable="false"
            >
                <form id="schedule-form" class="source-form" @submit.prevent="saveSchedule">
                    <Message v-if="scheduleError" severity="error" :closable="false">{{ scheduleError }}</Message>

                    <div class="form-grid">
                        <div class="field">
                            <label for="schedule-report">Report to deliver</label>
                            <Select
                                id="schedule-report"
                                v-model="scheduleForm.report_id"
                                :options="schedules.reports"
                                option-label="name"
                                option-value="id"
                                placeholder="Choose a report"
                                required
                                fluid
                            />
                        </div>
                        <div class="field">
                            <label for="schedule-format">Attachment format</label>
                            <Select
                                id="schedule-format"
                                v-model="scheduleForm.format"
                                :options="[
                                    { label: 'PDF document', value: 'pdf' },
                                    { label: 'Excel workbook', value: 'xlsx' },
                                ]"
                                option-label="label"
                                option-value="value"
                                fluid
                            />
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="schedule-frequency">Frequency</label>
                            <Select
                                id="schedule-frequency"
                                v-model="scheduleForm.frequency"
                                :options="['daily', 'weekly', 'monthly', 'custom']"
                                fluid
                                @change="applyFrequencyPreset"
                            />
                        </div>
                        <div class="field">
                            <label for="schedule-timezone">Timezone</label>
                            <Select
                                id="schedule-timezone"
                                v-model="scheduleForm.timezone"
                                :options="schedules.timezones"
                                filter
                                fluid
                            />
                        </div>
                    </div>

                    <div class="field">
                        <label for="schedule-cron">Cron expression</label>
                        <InputText id="schedule-cron" v-model="scheduleForm.cron_expression" required fluid />
                        <small>Five parts: minute, hour, day, month, weekday. Example: <code>0 8 * * 1</code> for Monday at 08:00.</small>
                    </div>

                    <div class="field">
                        <label for="schedule-channels">Delivery channels</label>
                        <MultiSelect
                            id="schedule-channels"
                            v-model="scheduleForm.delivery_channels"
                            :options="[
                                { label: 'Email attachment', value: 'email' },
                                { label: schedules.teams_configured ? 'Microsoft Teams' : 'Microsoft Teams (configuration required)', value: 'teams' },
                            ]"
                            option-label="label"
                            option-value="value"
                            display="chip"
                            fluid
                        />
                    </div>

                    <div v-if="scheduleForm.delivery_channels.includes('email')" class="field">
                        <label for="schedule-recipients">Email recipients</label>
                        <Textarea
                            id="schedule-recipients"
                            v-model="scheduleForm.recipients_text"
                            rows="2"
                            placeholder="finance@example.com, director@example.com"
                            fluid
                        />
                        <small>Separate addresses with commas. Recipient data is encrypted at rest.</small>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="schedule-date-from">Data from (optional)</label>
                            <Calendar
                                id="schedule-date-from"
                                v-model="scheduleForm.filters.date_from"
                                date-format="yy-mm-dd"
                                placeholder="YYYY-MM-DD"
                                show-icon
                                fluid
                            />
                            <small>Default: 1 month ago. Leave empty to include all data.</small>
                        </div>
                        <div class="field">
                            <label for="schedule-date-to">Data until (optional)</label>
                            <Calendar
                                id="schedule-date-to"
                                v-model="scheduleForm.filters.date_to"
                                date-format="yy-mm-dd"
                                placeholder="YYYY-MM-DD"
                                show-icon
                                fluid
                            />
                            <small>Default: today. Leave empty to include all data.</small>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="schedule-region">Region filter</label>
                            <InputText id="schedule-region" v-model="scheduleForm.filters.region" placeholder="Optional" fluid />
                        </div>
                        <div class="field">
                            <label for="schedule-status">Status filter</label>
                            <InputText id="schedule-status" v-model="scheduleForm.filters.status" placeholder="Optional" fluid />
                        </div>
                    </div>

                    <label class="schedule-active-control">
                        <input v-model="scheduleForm.is_active" type="checkbox" />
                        <span><strong>Active schedule</strong><small>Inactive schedules remain saved but are not dispatched.</small></span>
                    </label>
                </form>

                <template #footer>
                    <Button label="Cancel" severity="secondary" text @click="scheduleDialogOpen = false" />
                    <Button
                        form="schedule-form"
                        type="submit"
                        :label="editingScheduleId ? 'Save schedule' : 'Create schedule'"
                        icon="pi pi-check"
                        :loading="scheduleSaving"
                    />
                </template>
            </Dialog>


            <Dialog
                :visible="createUserDialogOpen"
                modal
                :closable="!createdUserCredentials"
                :close-on-escape="!createdUserCredentials"
                :header="createdUserCredentials ? 'Account created' : 'Add user'"
                :style="{ width: '560px', maxWidth: '95vw' }"
                @update:visible="value => value ? null : closeCreateUser()"
            >
                <!-- Credentials panel: shown once, cannot be recovered later. -->
                <div v-if="createdUserCredentials" class="new-user-credentials">
                    <Message severity="success" :closable="false">
                        {{ createdUserCredentials.name }}&rsquo;s account is ready.
                    </Message>

                    <div class="field">
                        <label>Temporary password</label>
                        <div class="temp-password-row">
                            <code>{{ createdUserCredentials.password }}</code>
                            <Button
                                icon="pi pi-copy"
                                severity="secondary"
                                outlined
                                size="small"
                                aria-label="Copy temporary password"
                                @click="copyTemporaryPassword"
                            />
                        </div>
                        <small>
                            This is shown only now. It is stored hashed, so it cannot be retrieved
                            again — generate a new one from the user row if it is lost.
                        </small>
                    </div>

                    <div class="field">
                        <label>Next steps</label>
                        <ul class="next-steps">
                            <li v-for="step in createdUserCredentials.nextSteps" :key="step">{{ step }}</li>
                        </ul>
                    </div>
                </div>

                <!-- Creation form -->
                <form v-else id="create-user-form" class="source-form" @submit.prevent="saveNewUser">
                    <Message v-if="createUserError" severity="error" :closable="false">
                        {{ createUserError }}
                    </Message>

                    <div class="form-grid">
                        <div class="field">
                            <label for="new-user-name">Full name</label>
                            <InputText id="new-user-name" v-model="createUserForm.name" required fluid />
                        </div>
                        <div class="field">
                            <label for="new-user-email">Work email</label>
                            <InputText
                                id="new-user-email"
                                v-model="createUserForm.email"
                                type="email"
                                autocomplete="off"
                                required
                                fluid
                            />
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="new-user-department">Department</label>
                            <InputText
                                id="new-user-department"
                                v-model="createUserForm.department"
                                placeholder="Optional"
                                fluid
                            />
                            <small>Controls which departmental dashboards and reports they can see.</small>
                        </div>
                        <div class="field">
                            <label for="new-user-title">Job title</label>
                            <InputText
                                id="new-user-title"
                                v-model="createUserForm.title"
                                placeholder="Optional"
                                fluid
                            />
                        </div>
                    </div>

                    <div class="field">
                        <label for="new-user-roles">Roles</label>
                        <MultiSelect
                            id="new-user-roles"
                            v-model="createUserForm.roles"
                            :options="adminUsers.roles"
                            option-label="label"
                            option-value="name"
                            display="chip"
                            placeholder="Select at least one role"
                            fluid
                        />
                        <small>Roles determine what the account can do. Assign the least that fits the job.</small>
                    </div>

                    <div class="field">
                        <label for="new-user-departments">Departments this user may view</label>
                        <MultiSelect
                            id="new-user-departments"
                            v-model="createUserForm.allowed_departments"
                            :options="adminUsers.departments"
                            display="chip"
                            filter
                            placeholder="Defaults to the department above"
                            fluid
                        />
                        <small>Roles decide which features the account can open; this decides whose data it sees.</small>
                    </div>

                    <label class="schedule-active-control">
                        <input v-model="createUserForm.restrict_data_sources" type="checkbox" />
                        <span>
                            <strong>Restrict to specific platforms</strong>
                            <small>When off, platform access follows the rules on each data source.</small>
                        </span>
                    </label>

                    <div v-if="createUserForm.restrict_data_sources" class="field">
                        <label for="new-user-sources">Platforms this user may view</label>
                        <MultiSelect
                            id="new-user-sources"
                            v-model="createUserForm.allowed_data_source_ids"
                            :options="adminUsers.data_sources"
                            option-label="name"
                            option-value="id"
                            display="chip"
                            filter
                            fluid
                        />
                        <small>Selecting none permits no platform.</small>
                    </div>

                    <label class="schedule-active-control">
                        <input v-model="createUserForm.is_active" type="checkbox" />
                        <span>
                            <strong>Active immediately</strong>
                            <small>Inactive accounts are saved but cannot sign in.</small>
                        </span>
                    </label>

                    <Message severity="info" :closable="false">
                        A strong temporary password is generated automatically. The user must change it
                        at first sign-in and set up two-step verification.
                    </Message>
                </form>

                <template #footer>
                    <template v-if="createdUserCredentials">
                        <Button label="Done" icon="pi pi-check" @click="closeCreateUser" />
                    </template>
                    <template v-else>
                        <Button label="Cancel" severity="secondary" text @click="closeCreateUser" />
                        <Button
                            form="create-user-form"
                            type="submit"
                            label="Create account"
                            icon="pi pi-user-plus"
                            :loading="createUserSaving"
                            :disabled="!createUserForm.roles.length"
                        />
                    </template>
                </template>
            </Dialog>
        </section>
    </div>
</template>
