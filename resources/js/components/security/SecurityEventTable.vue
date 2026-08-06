<script setup>
/**
 * Open security findings with a triage action.
 */
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import DataTable from '@/components/ui/DataTable.vue';
import { formatDateTime, severityFor } from '@/composables/useFormatters';

const props = defineProps({
    events: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    lastScanAt: { type: String, default: null },
});

const emit = defineEmits(['triage']);

const columns = [
    { key: 'severity', label: 'Severity' },
    { key: 'title', label: 'Finding' },
    { key: 'detector', label: 'Detector' },
    { key: 'subject', label: 'Subject' },
    { key: 'occurrences', label: 'Seen', numeric: true },
    { key: 'last_detected_at', label: 'Last detected' },
    ...(props.canManage ? [{ key: 'action', label: 'Action' }] : []),
];
</script>

<template>
    <DataTable
        :columns="columns"
        :rows="props.events"
        caption="Open security findings requiring review"
        :empty-text="props.lastScanAt
            ? `No open security findings. The agent last ran ${formatDateTime(props.lastScanAt)}.`
            : 'No open security findings.'"
    >
        <template #cell:severity="{ row }">
            <Tag :severity="severityFor('securitySeverity', row.severity)" :value="row.severity" />
        </template>

        <template #cell:title="{ row }">
            <strong>{{ row.title }}</strong>
            <div class="security-event-description">{{ row.description }}</div>
        </template>

        <template #cell:subject="{ row }">
            {{ row.user ?? row.ip_address ?? 'System' }}
        </template>

        <template #cell:last_detected_at="{ row }">
            {{ formatDateTime(row.last_detected_at) }}
        </template>

        <template #cell:action="{ row }">
            <Button label="Triage" size="small" text @click="emit('triage', row)" />
        </template>
    </DataTable>
</template>
