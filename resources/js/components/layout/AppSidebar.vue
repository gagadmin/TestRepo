<script setup>
/**
 * Primary navigation.
 *
 * Uses the established `.sidebar` / `.nav-item` styles from app.css — the
 * original markup structure, with items derived from the route table instead of
 * two hand-maintained arrays.
 */
import { useRouter } from 'vue-router';
import { useNavigation } from '@/composables/useNavigation';
import UserAccountLink from '@/components/layout/UserAccountLink.vue';
import { useAuthStore } from '@/stores/authStore';
import { useUiStore } from '@/stores/uiStore';

const { workspaceItems, adminItems } = useNavigation();
const auth = useAuthStore();
const ui = useUiStore();
const router = useRouter();

async function signOut() {
    await auth.logout();
    await router.push({ name: 'login' });
}
</script>

<template>
    <aside :class="['sidebar', { open: ui.sidebarOpen }]">
        <div class="brand sidebar-brand">
            <div class="brand-mark"><i class="pi pi-sparkles" aria-hidden="true"></i></div>
            <div><span>Ask GAHolding</span><small>INTELLIGENCE</small></div>
        </div>

        <nav aria-label="Primary navigation">
            <p v-if="workspaceItems.length" class="nav-label">Workspace</p>
            <RouterLink
                v-for="item in workspaceItems"
                :key="item.name"
                :to="{ name: item.name }"
                class="nav-item"
                active-class="active"
            >
                <i :class="['pi', item.icon]" aria-hidden="true"></i>
                <span>{{ item.label }}</span>
            </RouterLink>

            <p v-if="adminItems.length" class="nav-label admin-label">Administration</p>
            <RouterLink
                v-for="item in adminItems"
                :key="item.name"
                :to="{ name: item.name }"
                class="nav-item"
                active-class="active"
            >
                <i :class="['pi', item.icon]" aria-hidden="true"></i>
                <span>{{ item.label }}</span>
            </RouterLink>
        </nav>

        <div class="sidebar-profile">
            <UserAccountLink :user="auth.user" />
            <button type="button" title="Sign out" aria-label="Sign out" @click="signOut">
                <i class="pi pi-sign-out" aria-hidden="true"></i>
            </button>
        </div>
    </aside>
</template>

<style scoped>
/* RouterLink renders an anchor where the original used a button. */
.nav-item {
    text-decoration: none;
    /* Match the button reset the original relied on. */
    width: 100%;
    text-align: left;
}

</style>
