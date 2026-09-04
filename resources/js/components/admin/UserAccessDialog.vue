<script setup>
/**
 * Edit an existing account's identity, roles, and access profile.
 *
 * Both halves of the access profile are three-state, and the two toggles are
 * what make that visible: leaving a restriction off sends null ("not
 * configured"), turning it on and selecting nothing sends an empty list ("this
 * account sees none"). The distinction decides what a user can see, so the
 * screen states it in words rather than relying on an empty control.
 */
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import MultiSelect from 'primevue/multiselect';

defineProps({
    /** The reactive access form owned by `useUserDirectory`. */
    form: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    dataSources: { type: Array, default: () => [] },
    /** Selected platforms that authorize nobody, so the selection is inert. */
    inertPlatforms: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const visible = defineModel('visible', { type: Boolean, default: false });
const emit = defineEmits(['save']);
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        header="Edit user access"
        :style="{ width: 'min(620px, 94vw)' }"
        :draggable="false"
    >
        <form id="user-access-form" class="source-form" @submit.prevent="emit('save')">
            <Message v-if="error" severity="error" :closable="false">
                {{ error }}
            </Message>

            <div class="form-grid">
                <div class="field">
                    <label for="access-name">Full name</label>
                    <InputText id="access-name" v-model="form.name" required fluid />
                </div>
                <div class="field">
                    <label for="access-email">Email</label>
                    <InputText id="access-email" v-model="form.email" type="email" required fluid />
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="access-department">Department</label>
                    <InputText
                        id="access-department"
                        v-model="form.department"
                        list="department-options"
                        placeholder="Information Technology"
                        fluid
                    />
                    <datalist id="department-options">
                        <option v-for="department in departments" :key="department" :value="department"></option>
                    </datalist>
                    <small>Enter an existing or new department. This controls department-scoped dashboards, reports, and sources.</small>
                </div>
                <div class="field">
                    <label for="access-title">Job title</label>
                    <InputText id="access-title" v-model="form.title" placeholder="Service Desk Manager" fluid />
                </div>
            </div>

            <div class="field">
                <label for="access-roles">Roles</label>
                <MultiSelect
                    id="access-roles"
                    v-model="form.roles"
                    :options="roles"
                    option-label="label"
                    option-value="name"
                    display="chip"
                    required
                    fluid
                />
            </div>

            <label class="schedule-active-control">
                <input v-model="form.restrict_departments" type="checkbox" />
                <span>
                    <strong>Restrict to specific departments</strong>
                    <small>
                        When off, the user sees the department above. Turn it on to
                        choose the departments explicitly; selecting none then means
                        no departmental data at all.
                    </small>
                </span>
            </label>

            <div v-if="form.restrict_departments" class="field">
                <label for="access-allowed-departments">Departments this user may view</label>
                <MultiSelect
                    id="access-allowed-departments"
                    v-model="form.allowed_departments"
                    :options="departments"
                    display="chip"
                    filter
                    fluid
                />
                <small>
                    Department-scoped dashboards and reports are shown only for these
                    departments. Roles still decide which features the user can open.
                </small>
            </div>

            <label class="schedule-active-control">
                <input v-model="form.restrict_data_sources" type="checkbox" />
                <span>
                    <strong>Restrict to specific platforms</strong>
                    <small>
                        When off, platform access follows the authorized roles and
                        departments configured on each data source.
                    </small>
                </span>
            </label>

            <div v-if="form.restrict_data_sources" class="field">
                <label for="access-allowed-sources">Platforms this user may view</label>
                <MultiSelect
                    id="access-allowed-sources"
                    v-model="form.allowed_data_source_ids"
                    :options="dataSources"
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
                <Message v-if="inertPlatforms.length" severity="warn" :closable="false">
                    <strong>
                        {{ inertPlatforms.length === 1 ? 'This platform authorizes' : 'These platforms authorize' }}
                        no role or department, so selecting
                        {{ inertPlatforms.length === 1 ? 'it' : 'them' }} here has no effect:
                    </strong>
                    {{ inertPlatforms.map((source) => source.name).join(', ') }}.
                    Granting a platform narrows an audience the platform already defines - it
                    does not create one. Set Authorized roles or Authorized departments on
                    {{ inertPlatforms.length === 1 ? 'it' : 'each' }} under
                    Administration &rarr; Data sources, otherwise only its owner and
                    administrators can see it.
                </Message>
            </div>

            <label class="schedule-active-control">
                <input v-model="form.is_active" type="checkbox" />
                <span>
                    <strong>Active account</strong>
                    <small>Inactive users are denied login and lose existing sessions on their next request.</small>
                </span>
            </label>
        </form>

        <template #footer>
            <Button label="Cancel" severity="secondary" text @click="visible = false" />
            <Button
                form="user-access-form"
                type="submit"
                label="Save access"
                icon="pi pi-check"
                :loading="saving"
            />
        </template>
    </Dialog>
</template>
