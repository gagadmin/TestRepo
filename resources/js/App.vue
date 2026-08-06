<script setup>
/**
 * Application root.
 *
 * Its only job is to pick a layout and show a boot state. It owns no domain
 * state, makes no API calls, and imports no PrimeVue form components.
 *
 * Before the refactor this file was 4,784 lines holding 91 refs, 41 computed
 * properties, 80 functions and 38 axios calls across ten inlined views.
 */
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import GateLayout from '@/layouts/GateLayout.vue';
import LegacyLayout from '@/layouts/LegacyLayout.vue';
import { useAuthStore } from '@/stores/authStore';

const auth = useAuthStore();
const route = useRoute();

// The router guard resolves the session before the first navigation, so this
// only covers the initial paint.
const booting = computed(() => auth.loading && !route.name);

const layout = computed(() => ({
    auth: AuthLayout,
    // Mandatory identity steps: authenticated but confined, so no app nav.
    gate: GateLayout,
    // Temporary: the un-migrated monolith supplies its own chrome, so it is
    // rendered bare. Removed once the last view is extracted.
    legacy: LegacyLayout,
}[route.meta.layout] ?? AppLayout));
</script>

<template>
    <!-- Matches the original branded boot screen. -->
    <div v-if="booting" class="loading-screen" role="status" aria-live="polite">
        <div class="brand-mark"><i class="pi pi-sparkles"></i></div>
        <span>Preparing your intelligence workspace…</span>
    </div>

    <component :is="layout" v-else />
</template>
