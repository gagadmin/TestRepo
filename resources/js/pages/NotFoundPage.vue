<script setup>
import Button from 'primevue/button';
import PageHeader from '@/components/ui/PageHeader.vue';
</script>

<template>
    <PageHeader
        eyebrow="404"
        title="Page not found"
        description="That address does not match anything in the workspace."
    />

    <div class="table-empty panel">
        <i class="pi pi-compass" aria-hidden="true"></i>
        <strong>Nothing here</strong>
        <span>The link may be out of date, or you may not have access to it.</span>
        <RouterLink :to="{ name: 'overview' }">
            <Button label="Back to overview" icon="pi pi-home" size="small" />
        </RouterLink>
    </div>
</template>
