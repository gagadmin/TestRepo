<script setup>
/**
 * Authenticator management for the signed-in user.
 *
 * Regenerating codes and turning the factor off both require the current
 * password — otherwise a hijacked session could quietly strip the control.
 */
import { computed, ref } from 'vue';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Password from 'primevue/password';
import Tag from 'primevue/tag';
import { identityService } from '@/services/identityService';
import { useAsyncAction } from '@/composables/useAsyncResource';

const props = defineProps({
    status: { type: Object, required: true },
});

const emit = defineEmits(['refresh']);

const currentPassword = ref('');
const newCodes = ref([]);
const confirming = ref(null); // 'regenerate' | 'disable' | null

const regenerate = useAsyncAction(
    () => identityService.regenerateRecoveryCodes(currentPassword.value),
    {
        onSuccess: (result) => {
            newCodes.value = result.recovery_codes;
            currentPassword.value = '';
            confirming.value = null;
            emit('refresh');
        },
    },
);

const disable = useAsyncAction(
    () => identityService.disableTwoFactor(currentPassword.value),
    {
        onSuccess: () => {
            currentPassword.value = '';
            confirming.value = null;
            emit('refresh');
        },
    },
);

const activeAction = computed(() => (confirming.value === 'disable' ? disable : regenerate));

const lowOnCodes = computed(() => props.status.recovery_codes_remaining <= 2);

function start(action) {
    confirming.value = action;
    currentPassword.value = '';
    newCodes.value = [];
    regenerate.clearError();
    disable.clearError();
}

function cancel() {
    confirming.value = null;
    currentPassword.value = '';
}

async function copyCodes() {
    await navigator.clipboard?.writeText(newCodes.value.join('\n'));
}
</script>

<template>
    <div class="two-factor-settings">
        <div class="tfa-state">
            <div>
                <span class="muted">Authenticator app</span>
                <Tag
                    :severity="props.status.enabled ? 'success' : 'danger'"
                    :value="props.status.enabled ? 'Active' : 'Not set up'"
                />
            </div>
            <div v-if="props.status.enabled">
                <span class="muted">Recovery codes remaining</span>
                <Tag
                    :severity="lowOnCodes ? 'warn' : 'secondary'"
                    :value="String(props.status.recovery_codes_remaining)"
                />
            </div>
        </div>

        <Message v-if="props.status.enabled && lowOnCodes" severity="warn" :closable="false">
            You have {{ props.status.recovery_codes_remaining }} recovery code(s) left. If you lose
            your phone with none remaining, an administrator will have to reset your second factor.
            Generate a new set now.
        </Message>

        <Message v-if="!props.status.enabled" severity="warn" :closable="false">
            Two-step verification is not set up on this account.
            <template v-if="props.status.required">
                It is required, so you will be prompted at your next sign-in.
            </template>
        </Message>

        <!-- Freshly issued codes -->
        <div v-if="newCodes.length" class="tfa-codes-panel">
            <Message severity="success" :closable="false">
                New recovery codes issued. Your previous set no longer works.
            </Message>
            <ul class="recovery-codes" aria-label="New recovery codes">
                <li v-for="code in newCodes" :key="code">{{ code }}</li>
            </ul>
            <Button label="Copy" icon="pi pi-copy" severity="secondary" outlined size="small" @click="copyCodes" />
        </div>

        <!-- Confirmation step -->
        <form v-else-if="confirming" class="source-form tfa-confirm" novalidate @submit.prevent="activeAction.execute">
            <Message v-if="activeAction.error.value" severity="error" :closable="false">
                {{ activeAction.error.value }}
            </Message>

            <p class="muted">
                <template v-if="confirming === 'disable'">
                    Turning this off leaves your account protected by a password alone.
                    Confirm with your current password.
                </template>
                <template v-else>
                    Generating a new set invalidates your existing codes. Confirm with your current password.
                </template>
            </p>

            <div class="field">
                <label for="tfa-password">Current password</label>
                <Password
                    id="tfa-password"
                    v-model="currentPassword"
                    :feedback="false"
                    toggle-mask
                    autocomplete="current-password"
                    required
                    fluid
                />
            </div>

            <div class="tfa-actions">
                <Button
                    type="submit"
                    :label="confirming === 'disable' ? 'Turn off two-step verification' : 'Generate new codes'"
                    :severity="confirming === 'disable' ? 'danger' : 'primary'"
                    :loading="activeAction.saving.value"
                    :disabled="!currentPassword"
                    size="small"
                />
                <Button label="Cancel" severity="secondary" text size="small" @click="cancel" />
            </div>
        </form>

        <!-- Idle actions -->
        <div v-else-if="props.status.enabled" class="tfa-actions">
            <Button
                label="Generate new recovery codes"
                icon="pi pi-refresh"
                severity="secondary"
                outlined
                size="small"
                @click="start('regenerate')"
            />
            <Button
                v-if="!props.status.required"
                label="Turn off"
                icon="pi pi-times"
                severity="danger"
                text
                size="small"
                @click="start('disable')"
            />
            <span v-else class="muted tfa-locked-note">
                <i class="pi pi-lock" aria-hidden="true"></i>
                Required for your role, so it cannot be turned off.
            </span>
        </div>
    </div>
</template>

<style scoped>
.two-factor-settings { display: grid; gap: 14px; }

.tfa-state { display: flex; flex-wrap: wrap; gap: 28px; }
.tfa-state > div { display: grid; gap: 6px; justify-items: start; }
.tfa-state .muted { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }

.tfa-codes-panel { display: grid; gap: 12px; justify-items: start; }
.tfa-confirm { max-width: 380px; }
.tfa-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

.tfa-locked-note { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; }

.recovery-codes {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    width: 100%;
    max-width: 420px;
    margin: 0;
    padding: 14px;
    list-style: none;
    background: var(--mint);
    border: 1px solid var(--line);
    border-radius: 10px;
    font-family: ui-monospace, Menlo, monospace;
    font-size: 13px;
    letter-spacing: .06em;
}

@media (max-width: 600px) {
    .recovery-codes { grid-template-columns: 1fr; }
}
</style>
