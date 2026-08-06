<script setup>
/**
 * Triage dialog for a single finding.
 *
 * Closing a finding requires a written note. That rule is enforced server-side
 * too; this only surfaces it early so the user is not surprised by a 422.
 */
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';
import { severityFor } from '@/composables/useFormatters';

const props = defineProps({
    visible: { type: Boolean, default: false },
    event: { type: Object, default: null },
    form: { type: Object, required: true },
    requiresNote: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const emit = defineEmits(['update:visible', 'submit']);

const statusOptions = [
    { label: 'Open — still under investigation', value: 'open' },
    { label: 'Acknowledged — reviewed, action pending', value: 'acknowledged' },
    { label: 'Resolved — the underlying issue is fixed', value: 'resolved' },
    { label: 'False positive — not a real risk', value: 'false_positive' },
];
</script>

<template>
    <Dialog
        :visible="props.visible"
        modal
        header="Triage security finding"
        :style="{ width: '620px', maxWidth: '95vw' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div v-if="props.event" class="security-triage">
            <Message v-if="props.error" severity="error" :closable="false">
                {{ props.error }}
            </Message>

            <div class="security-triage-head">
                <Tag
                    :severity="severityFor('securitySeverity', props.event.severity)"
                    :value="props.event.severity"
                />
                <Tag
                    :severity="severityFor('securityStatus', props.event.status, 'secondary')"
                    :value="props.event.status"
                />
                <span class="muted">Seen {{ props.event.occurrences }}&times;</span>
            </div>

            <h3>{{ props.event.title }}</h3>
            <p class="security-event-description">{{ props.event.description }}</p>

            <div v-if="props.event.recommendation?.length" class="security-triage-block">
                <span class="eyebrow">Recommended actions</span>
                <ul>
                    <li v-for="(step, index) in props.event.recommendation" :key="index">
                        {{ step }}
                    </li>
                </ul>
            </div>

            <div v-if="props.event.evidence" class="security-triage-block">
                <span class="eyebrow">Evidence</span>
                <dl class="security-evidence">
                    <template v-for="(value, key) in props.event.evidence" :key="key">
                        <dt>{{ String(key).replace(/_/g, ' ') }}</dt>
                        <dd>{{ Array.isArray(value) ? value.join(', ') : String(value ?? '—') }}</dd>
                    </template>
                </dl>
            </div>

            <form id="security-triage-form" class="source-form" @submit.prevent="emit('submit')">
                <div class="field">
                    <label for="security-status">Set status</label>
                    <Select
                        id="security-status"
                        v-model="props.form.status"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        fluid
                    />
                </div>

                <div class="field">
                    <label for="security-note">
                        Resolution note
                        <span v-if="props.requiresNote" class="required-marker">required</span>
                    </label>
                    <Textarea
                        id="security-note"
                        v-model="props.form.resolution_note"
                        rows="3"
                        placeholder="What was investigated, what was found, and what action was taken."
                        :aria-required="props.requiresNote"
                        fluid
                    />
                    <small>Closing a finding is recorded in the audit trail against your account.</small>
                </div>
            </form>
        </div>

        <template #footer>
            <Button
                label="Cancel"
                severity="secondary"
                text
                @click="emit('update:visible', false)"
            />
            <Button
                form="security-triage-form"
                type="submit"
                label="Update finding"
                icon="pi pi-check"
                :loading="props.saving"
            />
        </template>
    </Dialog>
</template>
