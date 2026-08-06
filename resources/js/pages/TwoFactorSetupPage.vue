<script setup>
/**
 * Mandatory second-factor enrolment.
 *
 * Reached when the server reports enrolment is outstanding. Until it completes,
 * the session cannot reach business data (EnsureTwoFactorEnrolled), so this
 * page deliberately offers no way past it other than signing out.
 */
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import PageHeader from '@/components/ui/PageHeader.vue';
import { identityService } from '@/services/identityService';
import { useAsyncAction, useAsyncResource } from '@/composables/useAsyncResource';
import { useAuthStore } from '@/stores/authStore';

const auth = useAuthStore();
const router = useRouter();

const code = ref('');
const showSecret = ref(false);
const recoveryCodes = ref([]);
const acknowledged = ref(false);

const setup = useAsyncResource(() => identityService.beginSetup());

const confirm = useAsyncAction(
    () => identityService.confirmSetup(code.value.trim()),
    {
        onSuccess: (result) => {
            recoveryCodes.value = result.recovery_codes;
        },
    },
);

onMounted(setup.execute);

const codesText = computed(() => recoveryCodes.value.join('\n'));

async function copyCodes() {
    await navigator.clipboard?.writeText(codesText.value);
}

function downloadCodes() {
    // A local blob download avoids sending the codes anywhere.
    const blob = new Blob(
        [
            'Ask GAHolding recovery codes\n',
            `Account: ${auth.user?.email ?? ''}\n`,
            `Generated: ${new Date().toISOString()}\n\n`,
            'Each code works once. Store them somewhere only you can reach.\n\n',
            codesText.value,
            '\n',
        ],
        { type: 'text/plain' },
    );

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'ask-gaholding-recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(url);
}

async function finish() {
    // Re-read the session so the guard stops redirecting here.
    await auth.bootstrap();
    await router.push({ name: 'overview' });
}
</script>

<template>
    <PageHeader
        eyebrow="Required · one-time setup"
        title="Set up two-step verification"
        description="Your account needs a second factor before you can continue. It takes about a minute."
    />

    <!-- Stage 3: codes issued -->
    <template v-if="recoveryCodes.length">
        <Message severity="success" :closable="false">
            Two-step verification is on for your account.
        </Message>

        <article class="panel mfa-panel">
            <h2>Save your recovery codes</h2>
            <p class="muted">
                These are shown once and never again. Each one works a single time and lets you
                sign in if you lose your phone. Store them in a password manager or somewhere
                only you can reach — not in your email.
            </p>

            <ul class="recovery-codes" aria-label="Recovery codes">
                <li v-for="recoveryCode in recoveryCodes" :key="recoveryCode">{{ recoveryCode }}</li>
            </ul>

            <div class="mfa-actions">
                <Button label="Copy" icon="pi pi-copy" severity="secondary" outlined size="small" @click="copyCodes" />
                <Button label="Download" icon="pi pi-download" severity="secondary" outlined size="small" @click="downloadCodes" />
            </div>

            <label class="schedule-active-control">
                <input v-model="acknowledged" type="checkbox" />
                <span><strong>I have saved these codes somewhere safe</strong></span>
            </label>

            <Button
                label="Continue to Ask GAHolding"
                icon="pi pi-arrow-right"
                :disabled="!acknowledged"
                @click="finish"
            />
        </article>
    </template>

    <!-- Stages 1-2: scan then confirm -->
    <template v-else>
        <Message v-if="setup.error.value" severity="error" :closable="false">
            {{ setup.error.value }}
        </Message>
        <Message v-if="confirm.error.value" severity="error" closable @close="confirm.clearError">
            {{ confirm.error.value }}
        </Message>

        <div v-if="setup.isInitialLoading.value" class="source-empty panel" role="status" aria-live="polite">
            <i class="pi pi-spin pi-spinner" aria-hidden="true"></i>
            <span>Preparing your setup code…</span>
        </div>

        <article v-else-if="setup.data.value" class="panel mfa-panel">
            <ol class="mfa-steps">
                <li>
                    <strong>Install an authenticator app</strong>
                    <span class="muted">
                        Microsoft Authenticator, Google Authenticator, or 1Password all work.
                    </span>
                </li>
                <li>
                    <strong>Scan this code</strong>
                    <!-- Rendered server-side so the secret never leaves the app. -->
                    <div class="mfa-qr" v-html="setup.data.value.qr_code_svg"></div>

                    <Button
                        :label="showSecret ? 'Hide setup key' : 'Cannot scan? Enter a key instead'"
                        severity="secondary"
                        text
                        size="small"
                        @click="showSecret = !showSecret"
                    />
                    <code v-if="showSecret" class="mfa-secret">{{ setup.data.value.secret }}</code>
                </li>
                <li>
                    <strong>Enter the six-digit code it shows</strong>
                    <form class="source-form mfa-confirm" novalidate @submit.prevent="confirm.execute">
                        <InputText
                            id="mfa-confirm-code"
                            v-model="code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            placeholder="123456"
                            aria-label="Six-digit verification code"
                            class="mfa-code-input"
                        />
                        <Button
                            type="submit"
                            label="Confirm"
                            icon="pi pi-check"
                            :loading="confirm.saving.value"
                            :disabled="code.trim().length !== 6"
                        />
                    </form>
                </li>
            </ol>
        </article>
    </template>
</template>

<style scoped>
.mfa-panel { display: grid; gap: 14px; max-width: 620px; }
.mfa-panel h2 { margin: 0; font-size: 16px; }

.mfa-steps { display: grid; gap: 20px; margin: 0; padding-left: 20px; }
.mfa-steps li { display: grid; gap: 7px; }
.mfa-steps strong { color: var(--ink); font-size: 13px; }
.mfa-steps .muted { font-size: 12px; }

.mfa-qr {
    width: 200px;
    height: 200px;
    padding: 10px;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 10px;
}

.mfa-secret {
    display: inline-block;
    padding: 7px 11px;
    background: var(--mint);
    border-radius: 6px;
    font-size: 13px;
    letter-spacing: .12em;
    overflow-wrap: anywhere;
}

.mfa-confirm { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start; }
.mfa-code-input { max-width: 160px; font-family: ui-monospace, Menlo, monospace; letter-spacing: .18em; }
.mfa-actions { display: flex; flex-wrap: wrap; gap: 8px; }

.recovery-codes {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
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
    .mfa-qr { width: 100%; height: auto; }
}
</style>
