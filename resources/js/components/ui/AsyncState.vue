<script setup>
/**
 * Standard loading / error / empty / ready presentation.
 *
 * Every view in App.vue hand-rolled these four states with slightly different
 * markup and wording. One component means a consistent experience and one
 * place to improve it.
 */
import Button from 'primevue/button';
import Message from 'primevue/message';

const props = defineProps({
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    empty: { type: Boolean, default: false },
    loadingText: { type: String, default: 'Loading…' },
    emptyTitle: { type: String, default: 'Nothing to show yet' },
    emptyText: { type: String, default: '' },
    emptyIcon: { type: String, default: 'pi-inbox' },
    /** Show a retry button on the error state. */
    retryable: { type: Boolean, default: true },
});

const emit = defineEmits(['retry', 'dismiss-error']);
</script>

<template>
    <!--
      The error banner sits above content rather than replacing it, so a failed
      refresh does not discard data the user was already reading.
    -->
    <Message
        v-if="props.error"
        severity="error"
        :closable="true"
        @close="emit('dismiss-error')"
    >
        <div class="async-state-error">
            <span>{{ props.error }}</span>
            <Button
                v-if="props.retryable"
                label="Retry"
                icon="pi pi-refresh"
                size="small"
                text
                @click="emit('retry')"
            />
        </div>
    </Message>

    <div
        v-if="props.loading"
        class="source-empty panel"
        role="status"
        aria-live="polite"
    >
        <i class="pi pi-spin pi-spinner" aria-hidden="true"></i>
        <span>{{ props.loadingText }}</span>
    </div>

    <div v-else-if="props.empty" class="table-empty panel">
        <i :class="['pi', props.emptyIcon]" aria-hidden="true"></i>
        <strong>{{ props.emptyTitle }}</strong>
        <span v-if="props.emptyText">{{ props.emptyText }}</span>
        <slot name="empty-action" />
    </div>

    <slot v-else />
</template>

<style scoped>
.async-state-error {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
</style>
