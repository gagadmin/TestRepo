<script setup>
/**
 * Layout for the mandatory identity steps (enrolment, forced password change).
 *
 * These render outside the app shell on purpose. The session is authenticated
 * but confined by middleware, so showing the normal sidebar would offer links
 * that every one of them returns 403 for. A focused screen with only the brand
 * and a sign-out escape is honest about where the user is.
 */
import Button from 'primevue/button';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/authStore';

const auth = useAuthStore();
const router = useRouter();

async function signOut() {
    await auth.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div class="gate-shell">
        <header class="gate-header">
            <div class="brand">
                <div class="brand-mark"><i class="pi pi-sparkles" aria-hidden="true"></i></div>
                <span>Ask GAHolding</span>
            </div>

            <div class="gate-identity">
                <span class="gate-user">{{ auth.user?.email }}</span>
                <Button
                    label="Sign out"
                    icon="pi pi-sign-out"
                    severity="secondary"
                    text
                    size="small"
                    @click="signOut"
                />
            </div>
        </header>

        <main class="gate-body">
            <RouterView />
        </main>
    </div>
</template>

<style scoped>
.gate-shell {
    display: grid;
    grid-template-rows: auto minmax(0, 1fr);
    min-height: 100vh;
    background: var(--wash, #f5f8f7);
}

.gate-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 28px;
    background: #fff;
    border-bottom: 1px solid var(--line);
}

.gate-identity {
    display: flex;
    align-items: center;
    gap: 10px;
}

.gate-user {
    color: var(--muted);
    font-size: 11px;
}

.gate-body {
    display: grid;
    align-content: start;
    gap: 16px;
    width: 100%;
    max-width: 720px;
    margin: 0 auto;
    padding: 32px 20px 48px;
}

@media (max-width: 600px) {
    .gate-header { padding: 14px 18px; }
    .gate-user { display: none; }
    .gate-body { padding: 22px 16px 36px; }
}
</style>
