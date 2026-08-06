<script setup>
/**
 * Sign-in: password, then second factor.
 *
 * Layout and styling are the original two-panel auth design (classes defined in
 * resources/css/app.css). The second-factor step reuses the same login-card so
 * the transition between steps is a content swap rather than a new screen.
 */
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import { useAuthStore } from '@/stores/authStore';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const credentials = reactive({ email: '', password: '', remember: false });
const code = ref('');
const codeField = ref(null);

const lockedMinutes = computed(() => (
    auth.lockedForSeconds ? Math.max(1, Math.ceil(auth.lockedForSeconds / 60)) : null
));

// Focus the code field as soon as the challenge appears so an authenticator
// user can type straight away.
watch(() => auth.twoFactorPending, async (pending) => {
    if (!pending) return;

    await nextTick();
    codeField.value?.$el?.focus?.();
});

async function submitPassword() {
    const result = await auth.login(credentials);

    if (result.ok && !result.twoFactorRequired) {
        await finish();
    }
}

async function submitCode() {
    const result = await auth.verifyTwoFactor(code.value.trim());
    code.value = '';

    if (result.ok) {
        await finish();
    }
}

async function startOver() {
    await auth.cancelTwoFactor();
    code.value = '';
    credentials.password = '';
}

async function finish() {
    // The router guard diverts to enrolment or a password change when the
    // server reports either is outstanding.
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null;
    await router.push(redirect ?? { name: 'overview' });
}
</script>

<template>
    <main class="auth-shell">
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
            <!-- Step 2: second factor -->
            <form v-if="auth.twoFactorPending" class="login-card" @submit.prevent="submitCode">
                <div class="mobile-brand">
                    <div class="brand-mark"><i class="pi pi-sparkles"></i></div>
                    <span>Ask GAHolding</span>
                </div>
                <div>
                    <p class="eyebrow">Two-step verification</p>
                    <h2>Enter your verification code</h2>
                    <p class="form-intro">
                        Open your authenticator app and enter the six-digit code.
                        <template v-if="auth.recoveryCodesAvailable">
                            You can also use one of your recovery codes.
                        </template>
                    </p>
                </div>

                <div class="field">
                    <label for="mfa-code">Verification code</label>
                    <InputText
                        id="mfa-code"
                        ref="codeField"
                        v-model="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder="123456"
                        class="mfa-code-input"
                        fluid
                    />
                    <small class="field-hint">
                        Codes change every 30 seconds. A recovery code looks like A1B2C3-D4E5F6.
                    </small>
                </div>

                <p v-if="auth.error" class="form-error" role="alert">
                    <i class="pi pi-exclamation-circle"></i>{{ auth.error }}
                </p>

                <Button
                    type="submit"
                    label="Verify and sign in"
                    icon="pi pi-arrow-right"
                    icon-pos="right"
                    :loading="auth.submitting"
                    :disabled="code.trim().length < 6"
                />

                <button type="button" class="auth-link" @click="startOver">
                    Use a different account
                </button>
            </form>

            <!-- Step 1: password -->
            <form v-else class="login-card" @submit.prevent="submitPassword">
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
                    <InputText
                        id="email"
                        v-model="credentials.email"
                        type="email"
                        autocomplete="username"
                        fluid
                    />
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

                <label class="auth-remember">
                    <input v-model="credentials.remember" type="checkbox" />
                    <span>Keep me signed in</span>
                </label>

                <p v-if="auth.error" class="form-error" role="alert">
                    <i class="pi pi-exclamation-circle"></i>
                    <span>
                        {{ auth.error }}
                        <small v-if="lockedMinutes">
                            If this was not you, someone may be attempting to access your account.
                            Contact IT if it continues.
                        </small>
                    </span>
                </p>

                <Button
                    type="submit"
                    label="Sign in securely"
                    icon="pi pi-arrow-right"
                    icon-pos="right"
                    :loading="auth.submitting"
                />
                <p class="demo-note">
                    Access is restricted to accounts provisioned by your platform administrator.
                </p>
            </form>
        </section>
    </main>
</template>

<style scoped>
/*
  Only the additions the original design did not have: the remember checkbox,
  the code input treatment, and the "use a different account" link. Everything
  else comes from the shared auth styles in app.css.
*/
.auth-remember {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--muted);
    font-size: 12px;
    cursor: pointer;
}

.auth-remember input {
    width: 15px;
    height: 15px;
    accent-color: var(--teal-dark);
}

/* Monospace with tracking makes a six-digit code easy to check while typing. */
.mfa-code-input :deep(input),
.mfa-code-input {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 18px;
    letter-spacing: .2em;
}

.field-hint {
    color: var(--muted);
    font-size: 11px;
    line-height: 1.5;
}

.auth-link {
    padding: 0;
    border: 0;
    background: none;
    color: var(--teal-dark);
    font-size: 12px;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
}

.auth-link:hover { text-decoration: underline; }

.auth-link:focus-visible {
    outline: 2px solid var(--teal-dark);
    outline-offset: 3px;
    border-radius: 4px;
}

/* The lockout hint sits under the message rather than beside it. */
.form-error small {
    display: block;
    margin-top: 4px;
    opacity: .85;
    font-size: 11px;
    line-height: 1.5;
}
</style>
