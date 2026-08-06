<script setup>
/**
 * Consistent page header: eyebrow, title, description, and an actions slot.
 *
 * Replaces ten hand-written `<section class="welcome-row">` blocks that each
 * differed slightly in structure.
 */
const props = defineProps({
    eyebrow: { type: String, default: '' },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    /** Renders the title as h1 (page) or h2 (section). */
    level: { type: String, default: 'h1' },
});
</script>

<template>
    <section class="welcome-row intelligence-heading">
        <div>
            <p v-if="props.eyebrow" class="eyebrow">{{ props.eyebrow }}</p>
            <component :is="props.level">{{ props.title }}</component>
            <p v-if="props.description">{{ props.description }}</p>
            <slot name="subtitle" />
        </div>
        <div v-if="$slots.actions" class="page-header-actions">
            <slot name="actions" />
        </div>
    </section>
</template>

<style scoped>
.page-header-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
</style>
