import { computed, reactive, ref } from 'vue';
import { adminService } from '@/services/adminService';
import { useAsyncAction, useAsyncResource } from './useAsyncResource';

function emptyProfile() {
    /*
     * Both halves of the access profile are three-state and must round-trip as
     * such: "not configured" (null) is not "configured as empty" ([]). Null
     * falls back — to the department label, or to the rules on each data
     * source — while an empty list permits nothing. Collapsing them would
     * silently revoke a user's visibility the moment an administrator opened
     * the dialog and saved without touching it.
     */
    return {
        restrict_departments: false,
        allowed_departments: [],
        restrict_data_sources: false,
        allowed_data_source_ids: [],
    };
}

function emptyCreateForm() {
    return {
        name: '',
        email: '',
        department: '',
        title: '',
        is_active: true,
        roles: ['executive'],
        ...emptyProfile(),
    };
}

/**
 * Shape the access profile the way the API distinguishes its three states.
 *
 * Exported so the round-trip can be asserted directly; it is the part of this
 * screen where a mistake changes what a user can see.
 */
export function accessProfilePayload(form) {
    return {
        allowed_departments: form.restrict_departments
            ? form.allowed_departments ?? []
            : null,
        allowed_data_source_ids: form.restrict_data_sources
            ? form.allowed_data_source_ids ?? []
            : null,
    };
}

function identityPayload(form) {
    return {
        ...form,
        department: form.department.trim() || null,
        title: form.title.trim() || null,
        ...accessProfilePayload(form),
    };
}

/**
 * Users & access: the directory, the access dialog, and provisioning.
 *
 * `onSelfUpdated` is called when an administrator edits their own account, so
 * the caller can refresh the session — roles and permissions in the shell would
 * otherwise be stale until the next page load.
 */
export function useUserDirectory({ onSelfUpdated = null, currentUserId = null } = {}) {
    const page = ref(1);
    const search = ref('');

    const directory = useAsyncResource(
        () => adminService.users({ page: page.value, search: search.value }),
        {
            initialValue: {
                data: [],
                roles: [],
                departments: [],
                data_sources: [],
                meta: { current_page: 1, last_page: 1, total: 0 },
            },
        },
    );

    async function load(requested = 1) {
        page.value = Math.max(1, requested);

        return directory.execute();
    }

    const users = computed(() => directory.data.value?.data ?? []);
    const roles = computed(() => directory.data.value?.roles ?? []);
    const departments = computed(() => directory.data.value?.departments ?? []);
    const dataSources = computed(() => directory.data.value?.data_sources ?? []);
    const meta = computed(
        () => directory.data.value?.meta ?? { current_page: 1, last_page: 1, total: 0 },
    );
    const total = computed(() => meta.value.total ?? 0);
    const currentPage = computed(() => meta.value.current_page ?? 1);
    const lastPage = computed(() => meta.value.last_page ?? 1);
    const hasPrevious = computed(() => currentPage.value > 1);
    const hasNext = computed(() => currentPage.value < lastPage.value);

    async function previous() {
        if (hasPrevious.value) {
            return load(currentPage.value - 1);
        }
    }

    async function next() {
        if (hasNext.value) {
            return load(currentPage.value + 1);
        }
    }

    /** A new search always restarts at the first page. */
    async function applySearch() {
        return load(1);
    }

    /* ---- Access dialog ---- */

    const accessDialogOpen = ref(false);
    const editingUserId = ref(null);
    const accessForm = reactive({
        name: '', email: '', department: '', title: '', is_active: true, roles: [],
        ...emptyProfile(),
    });

    function openAccess(user) {
        editingUserId.value = user.id;
        Object.assign(accessForm, {
            name: user.name,
            email: user.email,
            department: user.department ?? '',
            title: user.title ?? '',
            is_active: user.is_active,
            roles: user.roles.map((role) => role.name),
            // An array means the profile is configured, including when empty.
            restrict_departments: Array.isArray(user.allowed_departments),
            allowed_departments: [...(user.allowed_departments ?? [])],
            restrict_data_sources: Array.isArray(user.allowed_data_source_ids),
            allowed_data_source_ids: [...(user.allowed_data_source_ids ?? [])],
        });
        saveAccess.clearError();
        accessDialogOpen.value = true;
    }

    const saveAccess = useAsyncAction(
        () => adminService.updateUser(editingUserId.value, identityPayload(accessForm)),
        {
            onSuccess: async () => {
                accessDialogOpen.value = false;
                await load(currentPage.value);

                if (onSelfUpdated && editingUserId.value === currentUserId) {
                    await onSelfUpdated();
                }
            },
        },
    );

    /**
     * Platforms selected for this user that authorize nobody, so the selection
     * is inert. The per-user list narrows the audience a source already
     * defines; it does not create one, so selecting a source that names neither
     * a role nor a department silently does nothing.
     */
    const inertSelectedPlatforms = computed(() => {
        if (! accessForm.restrict_data_sources) {
            return [];
        }

        return dataSources.value.filter((source) => (
            source.authorizes_anyone === false
            && (accessForm.allowed_data_source_ids ?? []).includes(source.id)
        ));
    });

    /* ---- Provisioning ---- */

    const createDialogOpen = ref(false);
    // Returned once by the API and never retrievable again.
    const createdCredentials = ref(null);
    const createForm = reactive(emptyCreateForm());

    function openCreate() {
        Object.assign(createForm, emptyCreateForm());
        createdCredentials.value = null;
        create.clearError();
        createDialogOpen.value = true;
    }

    const create = useAsyncAction(
        () => adminService.createUser(identityPayload(createForm)),
        {
            onSuccess: async (result) => {
                /*
                 * The dialog deliberately stays open on the credentials panel:
                 * the temporary password is shown once and closing the dialog
                 * loses the only copy.
                 */
                createdCredentials.value = {
                    name: result.data.name,
                    email: result.data.email,
                    password: result.temporary_password,
                    nextSteps: result.next_steps,
                };
                await load(1);
            },
        },
    );

    function closeCreate() {
        createDialogOpen.value = false;
        createdCredentials.value = null;
    }

    async function copyTemporaryPassword() {
        await navigator.clipboard?.writeText(createdCredentials.value?.password ?? '');
    }

    return {
        directory,
        loading: directory.loading,
        isInitialLoading: directory.isInitialLoading,
        error: directory.error,
        clearError: directory.clearError,

        page,
        search,
        load,
        applySearch,
        previous,
        next,

        users,
        roles,
        departments,
        dataSources,
        meta,
        total,
        currentPage,
        lastPage,
        hasPrevious,
        hasNext,

        accessDialogOpen,
        editingUserId,
        accessForm,
        openAccess,
        saveAccess,
        inertSelectedPlatforms,

        createDialogOpen,
        createForm,
        createdCredentials,
        openCreate,
        create,
        closeCreate,
        copyTemporaryPassword,
    };
}

export default useUserDirectory;
