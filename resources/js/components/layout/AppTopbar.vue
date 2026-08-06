<script setup>
/**
 * Top bar with breadcrumb, matching the original `.topbar` markup.
 *
 * The breadcrumb group is derived from the route's nav group rather than the
 * hardcoded arrays the original used.
 */
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { useUiStore } from '@/stores/uiStore';

const ui = useUiStore();
const route = useRoute();

const group = computed(() => ({
    admin: 'Administration',
    workspace: 'Intelligence',
}[route.meta.nav] ?? 'Workspace'));
</script>

<template>
    <header class="topbar">
        <button
            class="menu-button"
            type="button"
            aria-label="Open menu"
            @click="ui.toggleSidebar"
        >
            <i class="pi pi-bars" aria-hidden="true"></i>
        </button>

        <div class="breadcrumb">
            <span>{{ group }}</span>
            <i class="pi pi-angle-right" aria-hidden="true"></i>
            <strong>{{ route.meta.title ?? 'Overview' }}</strong>
        </div>

        <div class="topbar-actions">
            <Tag severity="success" value="Phase 6 live" icon="pi pi-check-circle" />
            <Button icon="pi pi-bell" severity="secondary" text rounded aria-label="Notifications" />
        </div>
    </header>
</template>
