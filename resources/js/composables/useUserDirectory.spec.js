import { beforeEach, describe, expect, it, vi } from 'vitest';
import { accessProfilePayload, useUserDirectory } from './useUserDirectory';
import { adminService } from '@/services/adminService';

vi.mock('@/services/adminService', () => ({
    adminService: {
        users: vi.fn(),
        createUser: vi.fn(),
        updateUser: vi.fn(),
    },
}));

/**
 * Users & access behaviour.
 *
 * The access profile is the part that decides what a user can see, and both
 * halves are three-state: "not configured" (null) is not "configured as empty"
 * ([]). Collapsing them silently revokes or restores visibility, so the
 * round-trip is asserted in both directions.
 */
describe('useUserDirectory', () => {
    const directoryPage = (overrides = {}) => ({
        data: [{ id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true }],
        roles: [{ name: 'analyst', label: 'Analyst' }],
        departments: ['Finance', 'Marketing'],
        data_sources: [
            { id: 1, name: 'Group ERP', authorizes_anyone: true },
            { id: 2, name: 'Inert ERP', authorizes_anyone: false },
        ],
        meta: { current_page: 1, last_page: 3, total: 42 },
        ...overrides,
    });

    beforeEach(() => {
        vi.clearAllMocks();
        adminService.users.mockResolvedValue(directoryPage());
        adminService.updateUser.mockResolvedValue({});
        adminService.createUser.mockResolvedValue({
            data: { name: 'New Person', email: 'new@example.test' },
            temporary_password: 'Temp-Password-1!',
            next_steps: ['Sign in', 'Change the password'],
        });
    });

    /* ---- Access profile round-trip ---- */

    it('sends null for a restriction that is switched off', () => {
        // Null means "this gate does not apply", which is what an unconfigured
        // account must keep.
        expect(accessProfilePayload({
            restrict_departments: false,
            allowed_departments: ['Finance'],
            restrict_data_sources: false,
            allowed_data_source_ids: [1],
        })).toEqual({ allowed_departments: null, allowed_data_source_ids: null });
    });

    it('sends an empty list for a restriction switched on with nothing selected', () => {
        // The administrator's way of saying "this account sees none".
        expect(accessProfilePayload({
            restrict_departments: true,
            allowed_departments: [],
            restrict_data_sources: true,
            allowed_data_source_ids: [],
        })).toEqual({ allowed_departments: [], allowed_data_source_ids: [] });
    });

    it('reads a configured empty list back as restricted, not unconfigured', async () => {
        /*
         * The direction that matters on load: an account explicitly restricted
         * to nothing must not reopen as "not restricted", or saving the dialog
         * would silently hand its visibility back.
         */
        const d = useUserDirectory();
        await d.load();

        d.openAccess({
            id: 7,
            name: 'Mara',
            email: 'm@example.test',
            roles: [],
            is_active: true,
            allowed_departments: [],
            allowed_data_source_ids: [],
        });

        expect(d.accessForm.restrict_departments).toBe(true);
        expect(d.accessForm.restrict_data_sources).toBe(true);
    });

    it('reads a missing profile back as unrestricted', async () => {
        const d = useUserDirectory();
        await d.load();

        d.openAccess({ id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true });

        expect(d.accessForm.restrict_departments).toBe(false);
        expect(d.accessForm.restrict_data_sources).toBe(false);
    });

    it('round-trips an unconfigured account without changing it', async () => {
        const d = useUserDirectory();
        await d.load();
        d.openAccess({ id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true });

        await d.saveAccess.execute();

        const [, payload] = adminService.updateUser.mock.calls[0];
        expect(payload.allowed_departments).toBeNull();
        expect(payload.allowed_data_source_ids).toBeNull();
    });

    /* ---- Identity payload ---- */

    it('sends null rather than an empty string for a blank department or title', async () => {
        const d = useUserDirectory();
        await d.load();
        d.openAccess({
            id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true,
            department: '  ', title: '',
        });

        await d.saveAccess.execute();

        const [userId, payload] = adminService.updateUser.mock.calls[0];
        expect(userId).toBe(7);
        expect(payload.department).toBeNull();
        expect(payload.title).toBeNull();
    });

    /* ---- Self-edit ---- */

    it('refreshes the session when an administrator edits their own account', async () => {
        // Roles can change, and the shell's navigation is built from them.
        const onSelfUpdated = vi.fn();
        const d = useUserDirectory({ onSelfUpdated, currentUserId: 7 });
        await d.load();
        d.openAccess({ id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true });

        await d.saveAccess.execute();

        expect(onSelfUpdated).toHaveBeenCalled();
    });

    it('does not refresh the session when editing somebody else', async () => {
        const onSelfUpdated = vi.fn();
        const d = useUserDirectory({ onSelfUpdated, currentUserId: 99 });
        await d.load();
        d.openAccess({ id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true });

        await d.saveAccess.execute();

        expect(onSelfUpdated).not.toHaveBeenCalled();
    });

    it('returns to the page it was on after saving', async () => {
        adminService.users.mockResolvedValue(
            directoryPage({ meta: { current_page: 2, last_page: 3, total: 42 } }),
        );
        const d = useUserDirectory();
        await d.load(2);
        d.openAccess({ id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true });
        adminService.users.mockClear();

        await d.saveAccess.execute();

        expect(adminService.users).toHaveBeenCalledWith({ page: 2, search: '' });
    });

    /* ---- Inert platforms ---- */

    it('flags selected platforms that authorize nobody', async () => {
        const d = useUserDirectory();
        await d.load();
        d.openAccess({
            id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true,
            allowed_data_source_ids: [1, 2],
        });

        expect(d.inertSelectedPlatforms.value.map((s) => s.id)).toEqual([2]);
    });

    it('flags nothing while the platform restriction is off', async () => {
        const d = useUserDirectory();
        await d.load();
        d.openAccess({ id: 7, name: 'Mara', email: 'm@example.test', roles: [], is_active: true });

        expect(d.inertSelectedPlatforms.value).toEqual([]);
    });

    /* ---- Provisioning ---- */

    it('keeps the credentials after creating an account', async () => {
        // The temporary password is shown once and stored hashed.
        const d = useUserDirectory();
        await d.load();
        d.openCreate();
        d.createForm.name = 'New Person';
        d.createForm.email = 'new@example.test';

        await d.create.execute();

        expect(d.createdCredentials.value.password).toBe('Temp-Password-1!');
        expect(d.createdCredentials.value.nextSteps).toHaveLength(2);
        expect(d.createDialogOpen.value).toBe(true);
    });

    it('reloads the first page after provisioning so the new account is visible', async () => {
        const d = useUserDirectory();
        await d.load(3);
        adminService.users.mockClear();
        d.openCreate();

        await d.create.execute();

        expect(adminService.users).toHaveBeenCalledWith({ page: 1, search: '' });
    });

    it('discards the credentials when the dialog is closed', async () => {
        const d = useUserDirectory();
        await d.load();
        d.openCreate();
        await d.create.execute();

        d.closeCreate();

        expect(d.createdCredentials.value).toBeNull();
        expect(d.createDialogOpen.value).toBe(false);
    });

    it('starts a new creation from a clean form', async () => {
        const d = useUserDirectory();
        await d.load();
        d.openCreate();
        d.createForm.name = 'Someone';
        d.createForm.restrict_departments = true;
        await d.create.execute();

        d.openCreate();

        expect(d.createForm.name).toBe('');
        expect(d.createForm.restrict_departments).toBe(false);
        expect(d.createdCredentials.value).toBeNull();
    });

    /* ---- Paging and search ---- */

    it('restarts at the first page for a new search', async () => {
        const d = useUserDirectory();
        await d.load(3);
        adminService.users.mockClear();
        d.search.value = 'mara';

        await d.applySearch();

        expect(adminService.users).toHaveBeenCalledWith({ page: 1, search: 'mara' });
    });

    it('does not page beyond either end', async () => {
        adminService.users.mockResolvedValue(
            directoryPage({ meta: { current_page: 1, last_page: 1, total: 1 } }),
        );
        const d = useUserDirectory();
        await d.load();
        adminService.users.mockClear();

        await d.previous();
        await d.next();

        expect(adminService.users).not.toHaveBeenCalled();
    });
});
