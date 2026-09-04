<script setup>
/**
 * Users & access page.
 *
 * Composition only: it wires `useUserDirectory` to the directory table and the
 * two admin dialogs. No axios, no paging arithmetic, no access-profile shaping
 * lives here.
 *
 * Extracted from `LegacyWorkspacePage.vue` as step two of the migration order
 * in `docs/frontend-architecture.md`.
 */
import { onMounted } from 'vue';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Tag from 'primevue/tag';

import AsyncState from '@/components/ui/AsyncState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import CreateUserDialog from '@/components/admin/CreateUserDialog.vue';
import UserAccessDialog from '@/components/admin/UserAccessDialog.vue';

import { useUserDirectory } from '@/composables/useUserDirectory';
import { useAuthStore } from '@/stores/authStore';
import { formatDateTime, formatNumber } from '@/composables/useFormatters';

const auth = useAuthStore();

const directory = useUserDirectory({
    currentUserId: auth.user?.id ?? null,
    // Editing your own account can change your roles, so the shell's navigation
    // and permissions must be re-read rather than left stale until a reload.
    onSelfUpdated: () => auth.bootstrap(),
});

onMounted(directory.load);
</script>

<template>
    <PageHeader
        eyebrow="Identity and authorization"
        title="Users &amp; access"
        description="Assign roles, departments, job titles, and account activation status."
    >
        <template #actions>
            <Tag
                severity="info"
                :value="`${formatNumber(directory.total.value)} users`"
                icon="pi pi-users"
            />
            <Button
                v-if="auth.can('users.manage')"
                label="Add user"
                icon="pi pi-user-plus"
                @click="directory.openCreate"
            />
        </template>
    </PageHeader>

    <section class="panel admin-panel">
        <div class="admin-toolbar">
            <div>
                <InputText
                    v-model="directory.search.value"
                    placeholder="Search name, email, department, or title"
                    aria-label="Search users"
                    @keydown.enter="directory.applySearch"
                />
                <Button label="Search" icon="pi pi-search" outlined @click="directory.applySearch" />
            </div>
            <span>Departments are assigned to each user and control departmental visibility.</span>
        </div>

        <AsyncState
            :loading="directory.isInitialLoading.value"
            :error="directory.error.value"
            :empty="!directory.users.value.length"
            loading-text="Loading users…"
            empty-title="No users"
            empty-text="No users match the current search."
            empty-icon="pi-users"
            @retry="directory.load()"
            @dismiss-error="directory.clearError"
        >
            <div class="report-table-wrap">
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
                        <tr v-for="user in directory.users.value" :key="user.id">
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
                                    <Tag
                                        :severity="user.is_active ? 'success' : 'danger'"
                                        :value="user.is_active ? 'Active' : 'Inactive'"
                                    />
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
                            <td>{{ user.last_login_at ? formatDateTime(user.last_login_at) : 'Never' }}</td>
                            <td>
                                <Button
                                    v-if="auth.can('users.manage')"
                                    icon="pi pi-pencil"
                                    text
                                    rounded
                                    aria-label="Edit user access"
                                    @click="directory.openAccess(user)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AsyncState>

        <div class="admin-pagination">
            <Button
                label="Previous"
                icon="pi pi-angle-left"
                outlined
                :disabled="!directory.hasPrevious.value"
                @click="directory.previous"
            />
            <span>Page {{ directory.currentPage.value }} of {{ directory.lastPage.value }}</span>
            <Button
                label="Next"
                icon="pi pi-angle-right"
                icon-pos="right"
                outlined
                :disabled="!directory.hasNext.value"
                @click="directory.next"
            />
        </div>
    </section>

    <UserAccessDialog
        v-model:visible="directory.accessDialogOpen.value"
        :form="directory.accessForm"
        :roles="directory.roles.value"
        :departments="directory.departments.value"
        :data-sources="directory.dataSources.value"
        :inert-platforms="directory.inertSelectedPlatforms.value"
        :saving="directory.saveAccess.saving.value"
        :error="directory.saveAccess.error.value"
        @save="directory.saveAccess.execute"
    />

    <CreateUserDialog
        v-model:visible="directory.createDialogOpen.value"
        :form="directory.createForm"
        :roles="directory.roles.value"
        :departments="directory.departments.value"
        :data-sources="directory.dataSources.value"
        :credentials="directory.createdCredentials.value"
        :saving="directory.create.saving.value"
        :error="directory.create.error.value"
        @create="directory.create.execute"
        @close="directory.closeCreate"
        @copy-password="directory.copyTemporaryPassword"
    />
</template>
