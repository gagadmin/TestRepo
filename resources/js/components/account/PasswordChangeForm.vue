<script setup>
/**
 * Password change form.
 *
 * Extracted so the forced-change gate page and the profile page share one
 * implementation rather than diverging.
 */
import { computed, reactive } from 'vue';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Password from 'primevue/password';
import { identityService } from '@/services/identityService';
import { useAsyncAction } from '@/composables/useAsyncResource';

const props = defineProps({
    /** Payload from GET /api/account/password/policy. */
    policy: { type: Object, default: null },
    submitLabel: { type: String, default: 'Change password' },
});

const emit = defineEmits(['changed']);

const form = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const change = useAsyncAction(() => identityService.changePassword({ ...form }), {
    onSuccess: async () => {
        Object.assign(form, { current_password: '', password: '', password_confirmation: '' });
        emit('changed');
    },
});

const minLength = computed(() => props.policy?.min_length ?? 12);

const mismatch = computed(() => (
    form.password.length > 0
    && form.password_confirmation.length > 0
    && form.password !== form.password_confirmation
));

const tooShort = computed(() => form.password.length > 0 && form.password.length < minLength.value);

const canSubmit = computed(() => (
    form.current_password.length > 0
    && form.password.length > 0
    && !mismatch.value
    && !tooShort.value
));

defineExpose({ saving: change.saving });
</script>

<template>
    <div class="password-form">
        <Message v-if="change.error.value" severity="error" closable @close="change.clearError">
            {{ change.error.value }}
        </Message>

        <section v-if="props.policy" class="password-guidance">
            <h3>What makes a good password here</h3>
            <ul>
                <li v-for="line in props.policy.guidance" :key="line">{{ line }}</li>
            </ul>
            <p v-if="!props.policy.rotation_enabled" class="muted">
                We do not ask you to change this on a schedule. Frequent forced changes lead to
                weaker, predictable passwords, so we only ask if there is a reason to.
            </p>
            <p v-else class="muted">
                This password will need changing every {{ props.policy.max_age_days }} days.
            </p>
        </section>

        <form class="source-form" novalidate @submit.prevent="change.execute">
            <div class="field">
                <label for="current-password">Current password</label>
                <Password
                    id="current-password"
                    v-model="form.current_password"
                    :feedback="false"
                    toggle-mask
                    autocomplete="current-password"
                    required
                    fluid
                />
            </div>

            <div class="field">
                <label for="new-password">New password</label>
                <Password
                    id="new-password"
                    v-model="form.password"
                    :feedback="false"
                    toggle-mask
                    autocomplete="new-password"
                    :invalid="tooShort"
                    required
                    fluid
                />
                <small v-if="tooShort" class="field-error">
                    Use at least {{ minLength }} characters.
                </small>
                <small v-else class="muted">
                    A passphrase of three or four unrelated words is both stronger and easier to recall.
                </small>
            </div>

            <div class="field">
                <label for="confirm-password">Confirm new password</label>
                <Password
                    id="confirm-password"
                    v-model="form.password_confirmation"
                    :feedback="false"
                    toggle-mask
                    autocomplete="new-password"
                    :invalid="mismatch"
                    required
                    fluid
                />
                <small v-if="mismatch" class="field-error">Both entries must match.</small>
            </div>

            <Button
                type="submit"
                :label="props.submitLabel"
                icon="pi pi-check"
                :loading="change.saving.value"
                :disabled="!canSubmit"
            />
        </form>
    </div>
</template>

<style scoped>
.password-form { display: grid; gap: 16px; }

.password-guidance {
    display: grid;
    gap: 8px;
    padding: 14px 16px;
    background: var(--mint);
    border: 1px solid var(--line);
    border-radius: 10px;
}

.password-guidance h3 { margin: 0; font-size: 13px; }
.password-guidance ul { margin: 0; padding-left: 18px; font-size: 12px; line-height: 1.7; }
.password-guidance .muted { margin: 0; font-size: 11px; line-height: 1.6; }

.field-error { color: #a43c35; }
</style>
