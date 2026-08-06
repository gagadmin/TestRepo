<script setup>
/**
 * Profile — account security self-service.
 *
 * Scope is deliberately security only. Name, email, department and roles stay
 * administrator-managed: department feeds the dashboard visibility scope, so a
 * user who could edit it would be granting themselves access to another
 * department's data.
 */
import { onMounted } from 'vue';
import Tag from 'primevue/tag';
import PageHeader from '@/components/ui/PageHeader.vue';
import PasswordChangeForm from '@/components/account/PasswordChangeForm.vue';
import TwoFactorSettings from '@/components/account/TwoFactorSettings.vue';
import { identityService } from '@/services/identityService';
import { useAsyncResource } from '@/composables/useAsyncResource';
import { useAuthStore } from '@/stores/authStore';
import { useUiStore } from '@/stores/uiStore';
import { formatDateTime } from '@/composables/useFormatters';

const auth = useAuthStore();
const ui = useUiStore();

const policy = useAsyncResource(() => identityService.passwordPolicy());
const twoFactor = useAsyncResource(() => identityService.twoFactorStatus());

onMounted(() => {
    policy.execute();
    twoFactor.execute();
});

async function onPasswordChanged() {
    ui.flash('Your password has been changed.');
    await Promise.all([policy.execute(), auth.bootstrap()]);
}

async function refreshTwoFactor() {
    await Promise.all([twoFactor.execute(), auth.bootstrap()]);
}
</script>

<template>
    <PageHeader
        eyebrow="Your account"
        title="Profile & security"
        description="Manage your password and two-step verification."
    />

    <div class="page-stack">
        <!-- Identity, read-only -->
        <article class="panel profile-identity">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Signed in as</p>
                    <h2>{{ auth.user?.name }}</h2>
                </div>
                <div class="profile-roles">
                    <Tag
                        v-for="role in auth.roles"
                        :key="role.name ?? role"
                        severity="secondary"
                        :value="role.label ?? role.name ?? role"
                    />
                </div>
            </div>

            <dl class="profile-details">
                <div>
                    <dt>Email</dt>
                    <dd>{{ auth.user?.email }}</dd>
                </div>
                <div>
                    <dt>Department</dt>
                    <dd>{{ auth.user?.department ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Job title</dt>
                    <dd>{{ auth.user?.title ?? '—' }}</dd>
                </div>
                <div v-if="policy.data.value?.password_age_days !== null">
                    <dt>Password age</dt>
                    <dd>{{ policy.data.value?.password_age_days }} day(s)</dd>
                </div>
                <div v-if="twoFactor.data.value?.confirmed_at">
                    <dt>Two-step verification since</dt>
                    <dd>{{ formatDateTime(twoFactor.data.value.confirmed_at) }}</dd>
                </div>
            </dl>

            <p class="muted profile-note">
                Your name, email, department and roles are managed by an administrator.
                Contact IT if any of these are wrong.
            </p>
        </article>

        <!-- Two-step verification -->
        <article class="panel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Two-step verification</p>
                    <h2>Authenticator app</h2>
                    <span class="muted">
                        A second factor stops a stolen password being enough to reach your account.
                    </span>
                </div>
            </div>

            <div v-if="twoFactor.isInitialLoading.value" class="source-empty" role="status" aria-live="polite">
                <i class="pi pi-spin pi-spinner" aria-hidden="true"></i><span>Loading…</span>
            </div>

            <TwoFactorSettings
                v-else-if="twoFactor.data.value"
                :status="twoFactor.data.value"
                @refresh="refreshTwoFactor"
            />
        </article>

        <!-- Password -->
        <article class="panel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">Password</p>
                    <h2>Change your password</h2>
                </div>
            </div>

            <PasswordChangeForm
                :policy="policy.data.value"
                @changed="onPasswordChanged"
            />
        </article>
    </div>
</template>

<style scoped>
.profile-identity { display: grid; gap: 16px; }
.profile-identity h2 { margin: 0; font-size: 20px; }
.profile-roles { display: flex; flex-wrap: wrap; gap: 6px; }

.profile-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin: 0;
    padding-top: 14px;
    border-top: 1px solid var(--line);
}

.profile-details dt {
    color: var(--muted);
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.profile-details dd { margin: 4px 0 0; color: var(--ink); font-size: 13px; overflow-wrap: anywhere; }

.profile-note { margin: 0; font-size: 11px; line-height: 1.6; }
</style>
