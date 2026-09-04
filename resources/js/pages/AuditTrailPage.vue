<script setup>
/**
 * Audit trail page.
 *
 * Composition only: it wires `useAuditTrail` to shared presentational pieces.
 * No axios, no paging arithmetic, no formatting logic lives here.
 *
 * Extracted from `LegacyWorkspacePage.vue` as the first step of the migration
 * order in `docs/frontend-architecture.md`.
 */
import { onMounted } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Tag from 'primevue/tag';

import AsyncState from '@/components/ui/AsyncState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

import { useAuditTrail } from '@/composables/useAuditTrail';
import { formatDateTime, formatNumber } from '@/composables/useFormatters';

const audit = useAuditTrail();

onMounted(audit.load);
</script>

<template>
    <PageHeader
        eyebrow="Governance evidence"
        title="Audit trail"
        description="Review bounded authentication, administration, integration, reporting, and delivery events."
    >
        <template #actions>
            <Tag
                severity="info"
                :value="`${formatNumber(audit.total.value)} events`"
                icon="pi pi-shield"
            />
        </template>
    </PageHeader>

    <section class="panel admin-panel">
        <div class="audit-filters">
            <InputText
                v-model="audit.filters.event"
                placeholder="Filter event name"
                aria-label="Audit event filter"
            />
            <InputText
                v-model="audit.filters.date_from"
                type="date"
                aria-label="Audit start date"
            />
            <InputText
                v-model="audit.filters.date_to"
                type="date"
                aria-label="Audit end date"
            />
            <Button label="Apply" icon="pi pi-filter" outlined @click="audit.applyFilters" />
            <Button
                v-if="audit.isFiltered.value"
                label="Clear"
                icon="pi pi-times"
                severity="secondary"
                text
                @click="audit.clearFilters"
            />
        </div>

        <AsyncState
            :loading="audit.isInitialLoading.value"
            :error="audit.error.value"
            :empty="!audit.events.value.length"
            loading-text="Loading audit events…"
            empty-title="No audit events"
            empty-text="No audit events match the current filters."
            empty-icon="pi-shield"
            @retry="audit.load()"
            @dismiss-error="audit.clearError"
        >
            <div class="report-table-wrap">
                <table class="report-table admin-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Actor</th>
                            <th>Target</th>
                            <th>IP address</th>
                            <th>Evidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="event in audit.events.value" :key="event.id">
                            <td>{{ formatDateTime(event.created_at) }}</td>
                            <td><Tag severity="secondary" :value="event.event" /></td>
                            <td>
                                <div class="audit-actor">
                                    <strong>{{ event.actor?.name || 'System / unknown' }}</strong>
                                    <span v-if="event.actor">{{ event.actor.email }}</span>
                                </div>
                            </td>
                            <td>
                                {{ event.auditable_type || '—' }}
                                <span v-if="event.auditable_id"> #{{ event.auditable_id }}</span>
                            </td>
                            <td>{{ event.ip_address || '—' }}</td>
                            <td><code>{{ JSON.stringify(event.metadata || {}) }}</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AsyncState>

        <div class="admin-pagination">
            <Button
                label="Previous"
                icon="pi pi-angle-left"
                outlined
                :disabled="!audit.hasPrevious.value"
                @click="audit.previous"
            />
            <span>Page {{ audit.currentPage.value }} of {{ audit.lastPage.value }}</span>
            <Button
                label="Next"
                icon="pi pi-angle-right"
                icon-pos="right"
                outlined
                :disabled="!audit.hasNext.value"
                @click="audit.next"
            />
        </div>
    </section>
</template>
