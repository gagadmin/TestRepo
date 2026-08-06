<script setup>
/**
 * AI tools administration.
 *
 * Replaces the hard-coded allow list that caused the reported fault: Freshservice
 * was connected under Data Sources, but the assistant's tool list was compiled
 * into ToolRegistry, so no ITSM tool existed and the model correctly reported it
 * had no ITSM connector.
 */
import { onMounted } from 'vue';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import MultiSelect from 'primevue/multiselect';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';

import AsyncState from '@/components/ui/AsyncState.vue';
import DataTable from '@/components/ui/DataTable.vue';
import KpiCard from '@/components/ui/KpiCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useAiTools } from '@/composables/useAiTools';
import { formatDateTime, humanise } from '@/composables/useFormatters';

const ai = useAiTools();

onMounted(ai.loadAll);
</script>

<template>
    <PageHeader
        eyebrow="Assistant governance"
        title="AI tools"
        description="Decide which connected data sources the assistant can read, and review the corrections that shape its answers."
    >
        <template #actions>
            <Button
                label="Refresh"
                icon="pi pi-refresh"
                severity="secondary"
                outlined
                size="small"
                :loading="ai.catalogue.loading.value"
                @click="ai.loadAll"
            />
            <Button label="Add tool" icon="pi pi-plus" size="small" @click="ai.openCreate" />
        </template>
    </PageHeader>

    <AsyncState
        :loading="ai.catalogue.isInitialLoading.value"
        :error="ai.catalogue.error.value"
        loading-text="Loading AI tool configuration…"
        @retry="ai.loadAll"
        @dismiss-error="ai.catalogue.clearError"
    >
        <div class="page-stack">
            <!-- Problems worth acting on, stated before the list -->
            <Message
                v-if="ai.unreachableTools.value.length"
                severity="warn"
                :closable="false"
            >
                <strong>{{ ai.unreachableTools.value.length }} enabled tool(s) have no connected data source.</strong>
                They will always refuse. Connect a matching source under Data sources, or disable the tool:
                {{ ai.unreachableTools.value.map((tool) => tool.label).join(', ') }}
            </Message>

            <Message
                v-if="ai.uncoveredSources.value.length"
                severity="warn"
                :closable="false"
            >
                <strong>{{ ai.uncoveredSources.value.length }} connected source(s) are unreachable by the assistant.</strong>
                No enabled tool covers their type, so the assistant cannot answer questions about them:
                {{ ai.uncoveredSources.value.map((source) => source.name).join(', ') }}
            </Message>

            <Message
                v-if="ai.brokenHandlerTools.value.length"
                severity="error"
                :closable="false"
            >
                <strong>{{ ai.brokenHandlerTools.value.length }} tool(s) reference a handler that no longer exists</strong>
                and are being skipped. Edit them to select an implemented handler.
            </Message>

            <nav class="security-tabs" aria-label="AI tool sections">
                <button
                    v-for="item in ai.sections"
                    :key="item.id"
                    type="button"
                    :class="['security-tab', { active: ai.section.value === item.id }]"
                    :aria-current="ai.section.value === item.id ? 'page' : undefined"
                    @click="ai.section.value = item.id"
                >
                    <i :class="['pi', item.icon]" aria-hidden="true"></i>
                    <span>{{ item.label }}</span>
                    <Tag
                        v-if="item.id === 'corrections' && ai.pendingCorrectionCount.value"
                        severity="warn"
                        :value="String(ai.pendingCorrectionCount.value)"
                    />
                    <Tag
                        v-if="item.id === 'failures' && ai.failures.data.value?.length"
                        severity="danger"
                        :value="String(ai.failures.data.value.length)"
                    />
                </button>
            </nav>

            <!-- Tools -->
            <template v-if="ai.section.value === 'tools'">
                <section class="kpi-grid kpi-grid-4">
                    <KpiCard label="Tools enabled" :value="ai.meta.value.enabled_count" />
                    <KpiCard label="Tools total" :value="ai.meta.value.total_count" />
                    <KpiCard
                        label="Unreachable tools"
                        :tone="ai.unreachableTools.value.length ? 'critical' : 'good'"
                        :value="ai.unreachableTools.value.length"
                    />
                    <KpiCard
                        label="Result cache"
                        :format="false"
                        :value="`${ai.meta.value.cache_seconds ?? 0}s`"
                        hint="Reused results are labelled with their original retrieval time"
                    />
                </section>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Allow list</p>
                            <h2>What the assistant may call</h2>
                            <span class="muted">
                                A tool is only offered to the model when it is enabled and at least one
                                connected source matches its types.
                            </span>
                        </div>
                    </div>

                    <DataTable
                        :columns="[
                            { key: 'is_enabled', label: 'Enabled' },
                            { key: 'label', label: 'Tool' },
                            { key: 'handler_label', label: 'Handler' },
                            { key: 'source_types', label: 'Source types' },
                            { key: 'connected_source_count', label: 'Connected', numeric: true },
                            { key: 'updated_at', label: 'Updated' },
                            { key: 'actions', label: '' },
                        ]"
                        :rows="ai.tools.value"
                        max-height="620px"
                        empty-text="No tools configured. The assistant cannot read any data source."
                    >
                        <template #cell:is_enabled="{ row }">
                            <Button
                                :icon="row.is_enabled ? 'pi pi-check-circle' : 'pi pi-circle'"
                                :severity="row.is_enabled ? 'success' : 'secondary'"
                                text
                                rounded
                                :aria-label="row.is_enabled ? `Disable ${row.label}` : `Enable ${row.label}`"
                                :loading="ai.toggle.saving.value"
                                @click="ai.toggle.execute(row)"
                            />
                        </template>

                        <template #cell:label="{ row }">
                            <strong>{{ row.label }}</strong>
                            <div class="tool-name"><code>{{ row.name }}</code></div>
                        </template>

                        <template #cell:handler_label="{ row }">
                            <Tag
                                :severity="row.handler_valid ? 'secondary' : 'danger'"
                                :value="row.handler_valid ? row.handler_label : `${row.handler} (missing)`"
                            />
                        </template>

                        <template #cell:source_types="{ row }">
                            <Tag
                                v-if="row.is_standalone"
                                severity="info"
                                value="Web (no data source)"
                            />
                            <div v-else class="type-tags">
                                <Tag
                                    v-for="type in row.source_types"
                                    :key="type"
                                    severity="secondary"
                                    :value="humanise(type)"
                                />
                            </div>
                        </template>

                        <template #cell:connected_source_count="{ row }">
                            <Tag
                                v-if="row.is_standalone"
                                :severity="row.provider_configured ? 'success' : 'danger'"
                                :value="row.provider_configured ? 'Provider set' : 'Not configured'"
                            />
                            <Tag
                                v-else
                                :severity="row.connected_source_count > 0 ? 'success' : 'danger'"
                                :value="String(row.connected_source_count)"
                            />
                        </template>

                        <template #cell:updated_at="{ row }">
                            {{ formatDateTime(row.updated_at) }}
                            <div v-if="row.updated_by" class="muted tool-name">by {{ row.updated_by }}</div>
                        </template>

                        <template #cell:actions="{ row }">
                            <div class="row-actions">
                                <Button
                                    icon="pi pi-pencil"
                                    text
                                    rounded
                                    :aria-label="`Edit ${row.label}`"
                                    @click="ai.openEdit(row)"
                                />
                                <Button
                                    icon="pi pi-trash"
                                    severity="danger"
                                    text
                                    rounded
                                    :aria-label="`Remove ${row.label}`"
                                    @click="ai.remove.execute(row)"
                                />
                            </div>
                        </template>
                    </DataTable>
                </article>
            </template>

            <!-- Coverage -->
            <template v-else-if="ai.section.value === 'coverage'">
                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Source types</p>
                            <h2>Connected sources per type</h2>
                            <span class="muted">
                                A type with connected sources but no enabled tool is invisible to the assistant.
                            </span>
                        </div>
                    </div>

                    <DataTable
                        :columns="[
                            { key: 'label', label: 'Type' },
                            { key: 'value', label: 'Key' },
                            { key: 'connected_count', label: 'Connected', numeric: true },
                            { key: 'covered', label: 'Assistant access' },
                        ]"
                        :rows="ai.sourceTypes.value"
                        row-key="value"
                        max-height="560px"
                    >
                        <template #cell:value="{ row }"><code>{{ row.value }}</code></template>
                        <template #cell:covered="{ row }">
                            <Tag
                                v-if="ai.tools.value.some((tool) => tool.is_enabled && tool.source_types?.includes(row.value))"
                                severity="success"
                                value="Reachable"
                            />
                            <Tag
                                v-else-if="row.connected_count > 0"
                                severity="danger"
                                value="Connected but unreachable"
                            />
                            <Tag v-else severity="secondary" value="No sources" />
                        </template>
                    </DataTable>
                </article>
            </template>

            <!-- Failures -->
            <template v-else-if="ai.section.value === 'failures'">
                <Message severity="info" :closable="false">
                    Recorded so the assistant can explain why a connector did not answer, instead of
                    concluding the capability does not exist. Resolved entries reopen if the problem recurs.
                </Message>

                <article class="panel">
                    <DataTable
                        :columns="[
                            { key: 'tool_name', label: 'Tool' },
                            { key: 'source', label: 'Source' },
                            { key: 'reason', label: 'Reason' },
                            { key: 'message', label: 'Detail', truncate: true },
                            { key: 'occurrences', label: 'Count', numeric: true },
                            { key: 'last_failed_at', label: 'Last seen' },
                            { key: 'actions', label: '' },
                        ]"
                        :rows="ai.failures.data.value ?? []"
                        empty-text="No connector failures recorded."
                    >
                        <template #cell:tool_name="{ row }"><code>{{ row.tool_name }}</code></template>
                        <template #cell:source="{ row }">{{ row.source ?? '—' }}</template>
                        <template #cell:reason="{ row }">
                            <Tag severity="warn" :value="humanise(row.reason)" />
                        </template>
                        <template #cell:last_failed_at="{ row }">{{ formatDateTime(row.last_failed_at) }}</template>
                        <template #cell:actions="{ row }">
                            <Button
                                label="Resolve"
                                size="small"
                                text
                                :loading="ai.resolveFailure.saving.value"
                                @click="ai.resolveFailure.execute(row)"
                            />
                        </template>
                    </DataTable>
                </article>
            </template>

            <!-- Corrections -->
            <template v-else-if="ai.section.value === 'corrections'">
                <Message severity="warn" :closable="false">
                    <strong>The model does not learn from these automatically.</strong>
                    An approved correction is injected into future prompts as trusted guidance for
                    every user — so review the wording before approving. Rejected and pending entries
                    have no effect on any answer.
                </Message>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Feedback queue</p>
                            <h2>Reported answers</h2>
                        </div>
                        <Select
                            :model-value="ai.correctionFilter.value"
                            :options="[
                                { label: 'Pending review', value: 'pending' },
                                { label: 'Approved', value: 'approved' },
                                { label: 'Rejected', value: 'rejected' },
                            ]"
                            option-label="label"
                            option-value="value"
                            size="small"
                            aria-label="Filter corrections"
                            @update:model-value="ai.setCorrectionFilter"
                        />
                    </div>

                    <DataTable
                        :columns="[
                            { key: 'question', label: 'Question', truncate: true },
                            { key: 'correction', label: 'Suggested correction', truncate: true },
                            { key: 'topic', label: 'Topic' },
                            { key: 'reported_by', label: 'Reported by' },
                            { key: 'applied_count', label: 'Applied', numeric: true },
                            { key: 'created_at', label: 'Reported' },
                            { key: 'actions', label: '' },
                        ]"
                        :rows="ai.corrections.data.value?.data ?? []"
                        max-height="620px"
                        empty-text="Nothing in this queue."
                    >
                        <template #cell:topic="{ row }">{{ row.topic ?? '—' }}</template>
                        <template #cell:created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
                        <template #cell:actions="{ row }">
                            <Button
                                v-if="row.status === 'pending'"
                                label="Review"
                                size="small"
                                text
                                @click="ai.openReview(row)"
                            />
                            <Tag
                                v-else
                                :severity="row.status === 'approved' ? 'success' : 'secondary'"
                                :value="row.status"
                            />
                        </template>
                    </DataTable>
                </article>
            </template>
        </div>
    </AsyncState>

    <!-- Tool editor -->
    <Dialog
        v-model:visible="ai.dialogOpen.value"
        modal
        :header="ai.editingId.value ? 'Edit tool' : 'Add tool'"
        :style="{ width: '640px', maxWidth: '95vw' }"
    >
        <form id="ai-tool-form" class="source-form" @submit.prevent="ai.save.execute">
            <Message v-if="ai.save.error.value" severity="error" :closable="false">
                {{ ai.save.error.value }}
            </Message>

            <div class="form-grid">
                <div class="field">
                    <label for="tool-label">Display name</label>
                    <InputText id="tool-label" v-model="ai.form.label" required fluid />
                </div>
                <div class="field">
                    <label for="tool-name">Function name</label>
                    <InputText id="tool-name" v-model="ai.form.name" placeholder="get_itsm_ticket_summary" required fluid />
                    <small>Lowercase, underscores. This is the name the model calls.</small>
                </div>
            </div>

            <div class="field">
                <label for="tool-description">When should the assistant use this?</label>
                <Textarea
                    id="tool-description"
                    v-model="ai.form.description"
                    rows="4"
                    required
                    fluid
                />
                <small>
                    This text is the only thing telling the model when to call the tool. List the
                    questions it answers in plain language — a vague description means the tool is
                    never used and the assistant says the data is unavailable.
                </small>
            </div>

            <div class="field">
                <label for="tool-handler">Handler</label>
                <Select
                    id="tool-handler"
                    v-model="ai.form.handler"
                    :options="ai.handlers.value"
                    option-label="label"
                    option-value="value"
                    fluid
                />
                <small v-if="ai.selectedHandler.value">{{ ai.selectedHandler.value.description }}</small>
                <small v-if="!ai.isStandalone.value">
                    Handlers are implemented in code and cannot be added here. The endpoint always
                    comes from the data source, never from this form.
                </small>
                <small v-else-if="ai.usesAiProvider.value">
                    Handlers are implemented in code and cannot be added here. This tool searches the
                    public web using the OpenAI API key already configured for this application —
                    just choose a model below.
                </small>
                <small v-else>
                    Handlers are implemented in code and cannot be added here. This tool searches the
                    public web through the provider you configure below — set the endpoint, allowed
                    hosts and API key.
                </small>
            </div>

            <div v-if="!ai.isStandalone.value" class="field">
                <label for="tool-types">Data source types</label>
                <MultiSelect
                    id="tool-types"
                    v-model="ai.form.source_types"
                    :options="ai.sourceTypes.value"
                    option-label="label"
                    option-value="value"
                    display="chip"
                    filter
                    placeholder="Select at least one type"
                    fluid
                />
                <small>Only connected sources of these types will be read, subject to each user's access.</small>
            </div>

            <!-- OpenAI-backed web search: reuses the configured OpenAI key -->
            <template v-if="ai.isStandalone.value && ai.usesAiProvider.value">
                <Message severity="info" :closable="false">
                    This tool uses the OpenAI API key already configured for the application. No
                    endpoint or key is entered here — just pick a model.
                </Message>

                <div class="form-grid">
                    <div class="field">
                        <label for="oai-model">OpenAI model</label>
                        <InputText id="oai-model" v-model="ai.form.options.model" placeholder="gpt-4o" fluid />
                        <small>Must be a model that supports the Responses API web search tool.</small>
                    </div>
                    <div class="field">
                        <label for="oai-tokens">Max output tokens</label>
                        <InputNumber id="oai-tokens" v-model="ai.form.options.max_output_tokens" :min="256" :max="8000" fluid />
                    </div>
                </div>
            </template>

            <!-- Search-API web search: per-tool endpoint, hosts and key -->
            <template v-else-if="ai.isStandalone.value">
                <div class="field">
                    <label for="ws-endpoint">Provider endpoint</label>
                    <InputText
                        id="ws-endpoint"
                        v-model="ai.form.options.endpoint"
                        placeholder="https://api.search-provider.com/v1/search"
                        fluid
                    />
                    <small>HTTPS only. The query is sent to this endpoint; the model never supplies a URL.</small>
                </div>

                <div class="field">
                    <label for="ws-hosts">Allowed hosts</label>
                    <InputText
                        id="ws-hosts"
                        v-model="ai.form.options.allowed_hosts"
                        placeholder="api.search-provider.com"
                        fluid
                    />
                    <small>
                        Comma-separated. The endpoint host must be listed here — this is what keeps the
                        tool pinned to one provider rather than open web access.
                    </small>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="ws-auth">Auth scheme</label>
                        <Select
                            id="ws-auth"
                            v-model="ai.form.options.auth_scheme"
                            :options="[
                                { label: 'Bearer token', value: 'bearer' },
                                { label: 'Custom header', value: 'header' },
                            ]"
                            option-label="label"
                            option-value="value"
                            fluid
                        />
                    </div>
                    <div v-if="ai.form.options.auth_scheme === 'header'" class="field">
                        <label for="ws-header">Key header name</label>
                        <InputText id="ws-header" v-model="ai.form.options.key_header" placeholder="X-API-Key" fluid />
                    </div>
                </div>

                <div class="field">
                    <label for="ws-key">API key</label>
                    <InputText
                        id="ws-key"
                        v-model="ai.form.api_key"
                        type="password"
                        autocomplete="new-password"
                        :placeholder="ai.hasStoredApiKey.value ? '•••••••• (leave blank to keep)' : 'Provider API key'"
                        fluid
                    />
                    <small>
                        Stored encrypted and never shown again.
                        <template v-if="ai.hasStoredApiKey.value">A key is already stored — leave blank to keep it.</template>
                    </small>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="ws-max">Max results</label>
                        <InputNumber id="ws-max" v-model="ai.form.options.max_results" :min="1" :max="10" fluid />
                    </div>
                    <div class="field">
                        <label for="ws-timeout">Timeout (s)</label>
                        <InputNumber id="ws-timeout" v-model="ai.form.options.timeout_seconds" :min="1" :max="60" fluid />
                    </div>
                    <div class="field">
                        <label for="ws-cache">Cache (s)</label>
                        <InputNumber id="ws-cache" v-model="ai.form.options.cache_seconds" :min="0" :max="86400" fluid />
                    </div>
                </div>
            </template>

            <div class="form-grid">
                <div class="field">
                    <label for="tool-order">Sort order</label>
                    <InputNumber id="tool-order" v-model="ai.form.sort_order" :min="0" :max="9999" fluid />
                </div>
                <label class="schedule-active-control">
                    <input v-model="ai.form.is_enabled" type="checkbox" />
                    <span>
                        <strong>Enabled</strong>
                        <small>Disabled tools are never offered to the model.</small>
                    </span>
                </label>
            </div>
        </form>

        <template #footer>
            <Button label="Cancel" severity="secondary" text @click="ai.dialogOpen.value = false" />
            <Button
                form="ai-tool-form"
                type="submit"
                :label="ai.editingId.value ? 'Save tool' : 'Create tool'"
                icon="pi pi-check"
                :loading="ai.save.saving.value"
                :disabled="!ai.canSave.value"
            />
        </template>
    </Dialog>

    <!-- Correction review -->
    <Dialog
        v-model:visible="ai.reviewDialogOpen.value"
        modal
        header="Review correction"
        :style="{ width: '620px', maxWidth: '95vw' }"
    >
        <div v-if="ai.activeCorrection.value" class="security-triage">
            <Message v-if="ai.review.error.value" severity="error" :closable="false">
                {{ ai.review.error.value }}
            </Message>

            <div class="security-triage-block">
                <span class="eyebrow">Question asked</span>
                <p class="security-event-description">{{ ai.activeCorrection.value.question }}</p>
            </div>

            <div v-if="ai.activeCorrection.value.incorrect_answer" class="security-triage-block">
                <span class="eyebrow">Answer given</span>
                <p class="security-event-description">{{ ai.activeCorrection.value.incorrect_answer }}</p>
            </div>

            <form id="correction-review-form" class="source-form" @submit.prevent="ai.review.execute">
                <div class="field">
                    <label for="review-correction">Guidance to apply</label>
                    <Textarea id="review-correction" v-model="ai.reviewForm.correction" rows="4" fluid />
                    <small>
                        Edit before approving. Once approved this text is injected into future prompts
                        for every user, so keep it factual and specific.
                    </small>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="review-topic">Topic</label>
                        <InputText id="review-topic" v-model="ai.reviewForm.topic" placeholder="Optional" fluid />
                        <small>Helps match this guidance to relevant questions.</small>
                    </div>
                    <div class="field">
                        <label for="review-status">Decision</label>
                        <Select
                            id="review-status"
                            v-model="ai.reviewForm.status"
                            :options="[
                                { label: 'Approve — apply to future answers', value: 'approved' },
                                { label: 'Reject — discard', value: 'rejected' },
                            ]"
                            option-label="label"
                            option-value="value"
                            fluid
                        />
                    </div>
                </div>

                <div class="field">
                    <label for="review-note">Review note</label>
                    <InputText id="review-note" v-model="ai.reviewForm.review_note" placeholder="Optional" fluid />
                </div>
            </form>
        </div>

        <template #footer>
            <Button label="Cancel" severity="secondary" text @click="ai.reviewDialogOpen.value = false" />
            <Button
                form="correction-review-form"
                type="submit"
                label="Save decision"
                icon="pi pi-check"
                :loading="ai.review.saving.value"
            />
        </template>
    </Dialog>
</template>

<style scoped>
.tool-name { margin-top: 3px; font-size: 10px; }
.tool-name code { font-size: 10px; }
.type-tags { display: flex; flex-wrap: wrap; gap: 4px; }
.row-actions { display: flex; gap: 2px; }
</style>
