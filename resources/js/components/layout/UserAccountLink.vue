<script setup>
/**
 * Discoverable self-service entry shared by both application sidebars.
 *
 * Keeping this outside the legacy workspace prevents its temporary shell from
 * losing the Profile route while the remaining pages are migrated.
 */
import { computed } from 'vue';
import Avatar from 'primevue/avatar';

const props = defineProps({
    user: { type: Object, default: null },
});

const initial = computed(() => (props.user?.name ?? '?').trim().charAt(0) || '?');
const accessibleLabel = computed(() => (
    props.user?.name
        ? `Open Profile and security for ${props.user.name}`
        : 'Open Profile and security'
));
</script>

<template>
    <RouterLink
        :to="{ name: 'profile' }"
        class="sidebar-profile-link"
        :aria-label="accessibleLabel"
        title="Profile & security"
    >
        <Avatar :label="initial" shape="circle" />
        <div>
            <strong>{{ user?.name }}</strong>
            <span>Profile &amp; security</span>
        </div>
    </RouterLink>
</template>

<style scoped>
.sidebar-profile-link {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 4px;
    margin: -4px;
    border-radius: 9px;
    color: inherit;
    text-decoration: none;
}

.sidebar-profile-link:hover { background: rgba(255, 255, 255, .05); }

.sidebar-profile-link:focus-visible {
    outline: 2px solid #4ee0ca;
    outline-offset: 1px;
}

.sidebar-profile-link.router-link-active { background: rgba(55, 207, 185, .13); }
.sidebar-profile-link.router-link-active strong { color: #fff; }
</style>
