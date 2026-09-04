<script setup>
/**
 * Provision an account.
 *
 * Two states in one dialog. After a successful creation it switches to the
 * credentials panel and refuses to close on the escape key or the corner
 * cross: the temporary password is shown exactly once and is stored hashed, so
 * dismissing the dialog by accident loses the only copy.
 */
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import MultiSelect from 'primevue/multiselect';

defineProps({
    /** The reactive create form owned by `useUserDirectory`. */
    form: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    dataSources: { type: Array, default: () => [] },
    /** Set once the account exists; switches the dialog to the credentials panel. */
    credentials: { type: Object, default: null },
    saving: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const visible = defineModel('visible', { type: Boolean, default: false });
const emit = defineEmits(['create', 'close', 'copy-password']);
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :closable="!credentials"
        :close-on-escape="!credentials"
        :header="credentials ? 'Account created' : 'Add user'"
        :style="{ width: '560px', maxWidth: '95vw' }"
        @update:visible="value => value ? null : emit('close')"
    >
        <!-- Credentials panel: shown once, cannot be recovered later. -->
        <div v-if="credentials" class="new-user-credentials">
            <Message severity="success" :closable="false">
                {{ credentials.name }}&rsquo;s account is ready.
            </Message>

            <div class="field">
                <label>Temporary password</label>
                <div class="temp-password-row">
                    <code>{{ credentials.password }}</code>
                    <Button
                        icon="pi pi-copy"
                        severity="secondary"
                        outlined
                        size="small"
                        aria-label="Copy temporary password"
                        @click="emit('copy-password')"
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
                    <li v-for="step in credentials.nextSteps" :key="step">{{ step }}</li>
                </ul>
            </div>
        </div>

        <!-- Creation form -->
        <form v-else id="create-user-form" class="source-form" @submit.prevent="emit('create')">
            <Message v-if="error" severity="error" :closable="false">
                {{ error }}
            </Message>

            <div class="form-grid">
                <div class="field">
                    <label for="new-user-name">Full name</label>
                    <InputText id="new-user-name" v-model="form.name" required fluid />
                </div>
                <div class="field">
                    <label for="new-user-email">Work email</label>
                    <InputText
                        id="new-user-email"
                        v-model="form.email"
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
                        v-model="form.department"
                        placeholder="Optional"
                        fluid
                    />
                    <small>Controls which departmental dashboards and reports they can see.</small>
                </div>
                <div class="field">
                    <label for="new-user-title">Job title</label>
                    <InputText
                        id="new-user-title"
                        v-model="form.title"
                        placeholder="Optional"
                        fluid
                    />
                </div>
            </div>

            <div class="field">
                <label for="new-user-roles">Roles</label>
                <MultiSelect
                    id="new-user-roles"
                    v-model="form.roles"
                    :options="roles"
                    option-label="label"
                    option-value="name"
                    display="chip"
                    placeholder="Select at least one role"
                    fluid
                />
                <small>Roles determine what the account can do. Assign the least that fits the job.</small>
            </div>

            <label class="schedule-active-control">
                <input v-model="form.restrict_departments" type="checkbox" />
                <span>
                    <strong>Restrict to specific departments</strong>
                    <small>When off, the account sees the department above.</small>
                </span>
            </label>

            <div v-if="form.restrict_departments" class="field">
                <label for="new-user-departments">Departments this user may view</label>
                <MultiSelect
                    id="new-user-departments"
                    v-model="form.allowed_departments"
                    :options="departments"
                    display="chip"
                    filter
                    placeholder="Select none to permit no departmental data"
                    fluid
                />
                <small>Roles decide which features the account can open; this decides whose data it sees.</small>
            </div>

            <label class="schedule-active-control">
                <input v-model="form.restrict_data_sources" type="checkbox" />
                <span>
                    <strong>Restrict to specific platforms</strong>
                    <small>When off, platform access follows the rules on each data source.</small>
                </span>
            </label>

            <div v-if="form.restrict_data_sources" class="field">
                <label for="new-user-sources">Platforms this user may view</label>
                <MultiSelect
                    id="new-user-sources"
                    v-model="form.allowed_data_source_ids"
                    :options="dataSources"
                    option-label="name"
                    option-value="id"
                    display="chip"
                    filter
                    fluid
                />
                <small>Selecting none permits no platform.</small>
            </div>

            <label class="schedule-active-control">
                <input v-model="form.is_active" type="checkbox" />
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
            <template v-if="credentials">
                <Button label="Done" icon="pi pi-check" @click="emit('close')" />
            </template>
            <template v-else>
                <Button label="Cancel" severity="secondary" text @click="emit('close')" />
                <Button
                    form="create-user-form"
                    type="submit"
                    label="Create account"
                    icon="pi pi-user-plus"
                    :loading="saving"
                    :disabled="!form.roles.length"
                />
            </template>
        </template>
    </Dialog>
</template>
