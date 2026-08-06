<script setup>
/**
 * TEMPORARY passthrough layout for the un-migrated monolith.
 *
 * LegacyWorkspacePage still renders its own loading screen, login form,
 * sidebar and topbar, so it must not be nested inside AppLayout. This layout
 * exists only to render it bare.
 *
 * Delete this file, the 'legacy' branch in App.vue, and
 * pages/LegacyWorkspacePage.vue once every view has been migrated.
 */
</script>

<template>
    <RouterView />
</template>
