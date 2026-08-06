<script setup>
/**
 * SEO insights (Phase 1).
 *
 * Deterministic Search Console analysis: keywords in positions 6–20, CTR gaps,
 * market opportunities, and an SEO health score, plus a per-property category /
 * region profile that will seed the AI web-research in a later phase.
 */
import { onMounted, ref } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Textarea from 'primevue/textarea';

import AsyncState from '@/components/ui/AsyncState.vue';
import DataTable from '@/components/ui/DataTable.vue';
import KpiCard from '@/components/ui/KpiCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useSeoInsights } from '@/composables/useSeoInsights';

const seo = useSeoInsights();

// Which action-plan item is expanded (accordion — one open at a time).
const openPlanItem = ref(null);
function togglePlanItem(index) {
    openPlanItem.value = openPlanItem.value === index ? null : index;
}

onMounted(seo.init);
</script>

<template>
    <PageHeader
        eyebrow="Search performance"
        title="SEO insights"
        description="Search Console keywords closest to the Top 5, CTR gaps, and market opportunities — with an opportunity score, not a ranking guarantee."
    >
        <template #actions>
            <Select
                v-if="seo.sources.data.value.length"
                :model-value="seo.selectedSourceId.value"
                :options="seo.sources.data.value"
                option-label="name"
                option-value="id"
                size="small"
                aria-label="Choose a property"
                @update:model-value="seo.onSelectSource"
            />
            <Button
                label="Refresh"
                icon="pi pi-refresh"
                severity="secondary"
                outlined
                size="small"
                :loading="seo.insights.loading.value"
                @click="seo.loadInsights"
            />
        </template>
    </PageHeader>

    <AsyncState
        :loading="seo.sources.isInitialLoading.value"
        :error="seo.sources.error.value"
        loading-text="Loading SEO properties…"
        @retry="seo.init"
        @dismiss-error="seo.sources.clearError"
    >
        <Message v-if="!seo.sources.data.value.length" severity="info" :closable="false">
            No connected Google Search Console property is visible to you. Connect one under
            Data sources, then return here.
        </Message>

        <div v-else class="page-stack">
            <nav class="security-tabs" aria-label="SEO sections">
                <button
                    v-for="item in seo.sectionTabs"
                    :key="item.id"
                    type="button"
                    :class="['security-tab', { active: seo.section.value === item.id }]"
                    :aria-current="seo.section.value === item.id ? 'page' : undefined"
                    @click="seo.section.value = item.id"
                >
                    <i :class="['pi', item.icon]" aria-hidden="true"></i>
                    <span>{{ item.label }}</span>
                </button>
            </nav>

            <Message
                v-if="seo.data.value && !seo.available.value"
                severity="warn"
                :closable="false"
            >
                <strong>Search Console data is not available for this property.</strong>
                {{ seo.data.value.reason }}
            </Message>

            <template v-else>
                <!-- Opportunities -->
                <template v-if="seo.section.value === 'opportunities'">
                    <section class="kpi-grid kpi-grid-4">
                        <KpiCard label="SEO health" :format="false" :value="`${seo.health.value.score}/100`" />
                        <KpiCard label="Clicks" :value="seo.summary.value.clicks ?? 0" />
                        <KpiCard label="Impressions" :value="seo.summary.value.impressions ?? 0" />
                        <KpiCard
                            label="Avg position"
                            :format="false"
                            :value="String(seo.summary.value.position ?? '—')"
                        />
                    </section>

                    <Message severity="info" :closable="false">
                        These keywords have the strongest measured potential to reach the Top 5.
                        The score reflects proximity, demand and CTR headroom — it is an opportunity
                        ranking, <strong>not a guarantee</strong> of reaching the Top 5, which also
                        depends on competitors and Google's algorithm.
                    </Message>

                    <article class="panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Positions 6–20</p>
                                <h2>Closest to the Top 5</h2>
                                <span v-if="seo.window.value" class="muted">
                                    {{ seo.window.value.from }} → {{ seo.window.value.to }}
                                </span>
                            </div>
                        </div>

                        <DataTable
                            :columns="[
                                { key: 'keyword', label: 'Keyword' },
                                { key: 'position', label: 'Position', numeric: true },
                                { key: 'impressions', label: 'Impressions', numeric: true },
                                { key: 'ctr', label: 'CTR %', numeric: true },
                                { key: 'expected_ctr', label: 'Expected %', numeric: true },
                                { key: 'recoverable_clicks', label: 'Upside clicks', numeric: true },
                                { key: 'opportunity_score', label: 'Score', numeric: true },
                            ]"
                            :rows="seo.topOpportunities.value"
                            max-height="620px"
                            empty-text="No keywords in positions 6–20 above the impression threshold yet."
                        >
                            <template #cell:keyword="{ row }">
                                <strong>{{ row.keyword }}</strong>
                                <Tag v-if="row.is_brand" severity="secondary" value="brand" class="ml-1" />
                            </template>
                            <template #cell:opportunity_score="{ row }">
                                <Tag :severity="row.opportunity_score >= 60 ? 'success' : 'secondary'" :value="String(row.opportunity_score)" />
                            </template>
                        </DataTable>
                    </article>
                </template>

                <!-- CTR gaps -->
                <template v-else-if="seo.section.value === 'ctr'">
                    <article class="panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Under-clicked</p>
                                <h2>High impressions, low CTR</h2>
                                <span class="muted">Pages earning visibility but below the expected CTR for their position.</span>
                            </div>
                        </div>
                        <DataTable
                            :columns="[
                                { key: 'page', label: 'Page', truncate: true },
                                { key: 'impressions', label: 'Impressions', numeric: true },
                                { key: 'ctr', label: 'CTR %', numeric: true },
                                { key: 'expected_ctr', label: 'Expected %', numeric: true },
                                { key: 'position', label: 'Position', numeric: true },
                                { key: 'recoverable_clicks', label: 'Upside clicks', numeric: true },
                            ]"
                            :rows="seo.ctrGaps.value"
                            max-height="620px"
                            empty-text="No underperforming pages above the impression threshold."
                        />
                    </article>
                </template>

                <!-- Countries -->
                <template v-else-if="seo.section.value === 'countries'">
                    <article class="panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">Market expansion</p>
                                <h2>Country opportunities</h2>
                                <span class="muted">Demand present but capture weak — starting with your target regions.</span>
                            </div>
                        </div>
                        <DataTable
                            :columns="[
                                { key: 'country', label: 'Country' },
                                { key: 'impressions', label: 'Impressions', numeric: true },
                                { key: 'ctr', label: 'CTR %', numeric: true },
                                { key: 'position', label: 'Position', numeric: true },
                                { key: 'flags', label: '' },
                            ]"
                            :rows="seo.countries.value"
                            max-height="620px"
                            empty-text="No countries above the impression threshold."
                        >
                            <template #cell:flags="{ row }">
                                <Tag v-if="row.is_target_region" severity="info" value="target" class="ml-1" />
                                <Tag v-if="row.weak_capture" severity="warn" value="weak capture" class="ml-1" />
                            </template>
                        </DataTable>
                    </article>
                </template>

                <!-- Trends -->
                <template v-else-if="seo.section.value === 'trends'">
                    <Message v-if="!seo.trends.value.available" severity="info" :closable="false">
                        {{ seo.trends.value.reason || 'Collecting data — ranking trends appear once snapshots have accumulated over time.' }}
                    </Message>

                    <template v-else>
                        <Message severity="info" :closable="false">
                            Period-over-period movement: <strong>{{ seo.trends.value.baseline_on }}</strong>
                            → <strong>{{ seo.trends.value.latest_on }}</strong>. A higher position number
                            is worse, so a positive change means a decline.
                        </Message>

                        <article class="panel">
                            <div class="panel-heading">
                                <div>
                                    <p class="eyebrow">Losing ground</p>
                                    <h2>Declining keywords</h2>
                                </div>
                            </div>
                            <DataTable
                                :columns="[
                                    { key: 'keyword', label: 'Keyword' },
                                    { key: 'previous_position', label: 'Was', numeric: true },
                                    { key: 'position', label: 'Now', numeric: true },
                                    { key: 'delta', label: 'Change', numeric: true },
                                    { key: 'impressions', label: 'Impressions', numeric: true },
                                ]"
                                :rows="seo.trends.value.declining"
                                max-height="360px"
                                empty-text="No significant declines in this period."
                            >
                                <template #cell:delta="{ row }">
                                    <Tag severity="danger" :value="`+${row.delta}`" />
                                </template>
                            </DataTable>
                        </article>

                        <article class="panel">
                            <div class="panel-heading">
                                <div>
                                    <p class="eyebrow">Gaining ground</p>
                                    <h2>Improving keywords</h2>
                                </div>
                            </div>
                            <DataTable
                                :columns="[
                                    { key: 'keyword', label: 'Keyword' },
                                    { key: 'previous_position', label: 'Was', numeric: true },
                                    { key: 'position', label: 'Now', numeric: true },
                                    { key: 'delta', label: 'Change', numeric: true },
                                    { key: 'impressions', label: 'Impressions', numeric: true },
                                ]"
                                :rows="seo.trends.value.gaining"
                                max-height="360px"
                                empty-text="No significant gains in this period."
                            >
                                <template #cell:delta="{ row }">
                                    <Tag severity="success" :value="String(row.delta)" />
                                </template>
                            </DataTable>
                        </article>
                    </template>
                </template>

                <!-- Health -->
                <template v-else-if="seo.section.value === 'health'">
                    <section class="kpi-grid kpi-grid-4">
                        <KpiCard label="SEO health" :format="false" :value="`${seo.health.value.score}/100`" />
                        <KpiCard label="Position score" :format="false" :value="String(seo.health.value.breakdown.position ?? '—')" />
                        <KpiCard label="CTR score" :format="false" :value="String(seo.health.value.breakdown.ctr ?? '—')" />
                        <KpiCard label="Page-one share" :format="false" :value="String(seo.health.value.breakdown.page_one_share ?? '—')" />
                    </section>
                    <Message severity="info" :closable="false">
                        Health blends average position, CTR versus the position baseline, and the share
                        of impressions from page one. Trends and technical/competitor signals arrive in
                        later phases.
                    </Message>
                </template>

                <!-- Web research -->
                <template v-else-if="seo.section.value === 'research'">
                    <Message severity="info" :closable="false">
                        AI-gathered from the public web for your categories and region — competitors,
                        backlink ideas, and technical/content signals, each with a source link.
                        This is <strong>qualitative, cited intelligence</strong>, not exact metrics,
                        and is kept separate from the Search Console numbers.
                    </Message>

                    <div>
                        <Button
                            label="Run web research"
                            icon="pi pi-globe"
                            :loading="seo.generateResearch.saving.value"
                            @click="seo.generateResearch.execute"
                        />
                    </div>

                    <Message v-if="seo.generateResearch.error.value" severity="error" :closable="false">
                        {{ seo.generateResearch.error.value }}
                    </Message>

                    <template v-if="seo.researchFindings.value">
                        <article class="panel">
                            <div class="panel-heading"><div><p class="eyebrow">Competitors</p><h2>Who else ranks here</h2></div></div>
                            <DataTable
                                :columns="[
                                    { key: 'name', label: 'Name' },
                                    { key: 'domain', label: 'Domain' },
                                    { key: 'note', label: 'Why', truncate: true },
                                    { key: 'url', label: 'Source' },
                                ]"
                                :rows="seo.researchFindings.value.competitors || []"
                                max-height="320px"
                                empty-text="No competitors found."
                            >
                                <template #cell:url="{ row }">
                                    <a v-if="row.url" :href="row.url" target="_blank" rel="noopener">link</a>
                                </template>
                            </DataTable>
                        </article>

                        <article class="panel">
                            <div class="panel-heading"><div><p class="eyebrow">Backlink targets</p><h2>Where to earn links</h2></div></div>
                            <DataTable
                                :columns="[
                                    { key: 'name', label: 'Target' },
                                    { key: 'type', label: 'Type' },
                                    { key: 'why', label: 'Why', truncate: true },
                                    { key: 'url', label: 'Source' },
                                ]"
                                :rows="seo.researchFindings.value.backlink_targets || []"
                                max-height="320px"
                                empty-text="No backlink targets found."
                            >
                                <template #cell:url="{ row }">
                                    <a v-if="row.url" :href="row.url" target="_blank" rel="noopener">link</a>
                                </template>
                            </DataTable>
                        </article>

                        <article class="panel">
                            <div class="panel-heading"><div><p class="eyebrow">Technical & content</p><h2>Signals to act on</h2></div></div>
                            <DataTable
                                :columns="[
                                    { key: 'observation', label: 'Observation', truncate: true },
                                    { key: 'recommendation', label: 'Recommendation', truncate: true },
                                    { key: 'url', label: 'Source' },
                                ]"
                                :rows="seo.researchFindings.value.technical_signals || []"
                                max-height="320px"
                                empty-text="No technical signals found."
                            >
                                <template #cell:url="{ row }">
                                    <a v-if="row.url" :href="row.url" target="_blank" rel="noopener">link</a>
                                </template>
                            </DataTable>
                        </article>
                    </template>

                    <Message v-else severity="secondary" :closable="false">
                        No research yet. Set categories and a region on the Profile tab, then run it.
                    </Message>
                </template>

                <!-- AI action plan -->
                <template v-else-if="seo.section.value === 'plan'">
                    <Message severity="info" :closable="false">
                        The plan is generated by AI from the verified Search Console findings above.
                        It sequences actions and explains them — it does not invent metrics, and
                        rankings are framed as expected direction, <strong>not guarantees</strong>.
                    </Message>

                    <div>
                        <Button
                            label="Generate action plan"
                            icon="pi pi-sparkles"
                            :loading="seo.generatePlan.saving.value"
                            @click="seo.generatePlan.execute"
                        />
                    </div>

                    <Message v-if="seo.generatePlan.error.value" severity="error" :closable="false">
                        {{ seo.generatePlan.error.value }}
                    </Message>

                    <article v-if="seo.latestPlan.value" class="panel">
                        <div class="panel-heading">
                            <div>
                                <p class="eyebrow">
                                    {{ seo.latestPlan.value.provider }} · {{ seo.latestPlan.value.model }}
                                </p>
                                <h2>Action plan</h2>
                            </div>
                        </div>

                        <p v-if="seo.latestPlan.value.summary" class="plan-summary">
                            {{ seo.latestPlan.value.summary }}
                        </p>

                        <Message
                            v-if="!seo.latestPlan.value.items.length"
                            severity="warn"
                            :closable="false"
                        >
                            The model did not return structured actions this time. Click
                            “Generate action plan” to try again.
                        </Message>

                        <div v-else class="plan-items">
                            <div
                                v-for="(item, i) in seo.latestPlan.value.items"
                                :key="i"
                                class="plan-item"
                                :class="{ open: openPlanItem === i }"
                            >
                                <button
                                    type="button"
                                    class="plan-item-head"
                                    :aria-expanded="openPlanItem === i"
                                    @click="togglePlanItem(i)"
                                >
                                    <i
                                        class="pi plan-item-chevron"
                                        :class="openPlanItem === i ? 'pi-chevron-down' : 'pi-chevron-right'"
                                        aria-hidden="true"
                                    ></i>
                                    <Tag
                                        :severity="item.priority === 'high' ? 'danger' : (item.priority === 'medium' ? 'warn' : 'secondary')"
                                        :value="item.priority"
                                    />
                                    <Tag severity="secondary" :value="item.category" />
                                    <Tag v-if="item.requires_web_research" severity="info" value="needs research" />
                                    <strong class="plan-item-title">{{ item.title }}</strong>
                                </button>

                                <div v-if="openPlanItem === i" class="plan-item-body">
                                    <p v-if="item.rationale" class="muted plan-item-rationale">{{ item.rationale }}</p>

                                    <div class="plan-item-meta">
                                        <span v-if="item.expected_impact">
                                            <strong>Expected impact:</strong> {{ item.expected_impact }}
                                        </span>
                                        <span v-if="item.references && item.references.length">
                                            <strong>Applies to:</strong> {{ item.references.join(', ') }}
                                        </span>
                                    </div>

                                    <div v-if="item.recommendation" class="plan-rec-body">{{ item.recommendation }}</div>
                                </div>
                            </div>
                        </div>
                    </article>

                    <Message v-else severity="secondary" :closable="false">
                        No action plan yet — generate one from the current findings.
                    </Message>
                </template>

                <!-- Profile -->
                <template v-else-if="seo.section.value === 'profile'">
                    <Message severity="info" :closable="false">
                        Categories and target regions scope your insights and will drive the AI
                        web-research (competitor gaps, backlink targets, technical signals) in a later
                        phase — searching the live web for these categories in your region, with cited
                        sources.
                    </Message>

                    <article class="panel">
                        <form class="source-form" @submit.prevent="seo.saveProfile.execute">
                            <Message v-if="seo.saveProfile.error.value" severity="error" :closable="false">
                                {{ seo.saveProfile.error.value }}
                            </Message>

                            <div class="field">
                                <label for="seo-categories">Categories</label>
                                <InputText id="seo-categories" v-model="seo.profileForm.categories" placeholder="automotive, spare parts, export cars" fluid />
                                <small>Comma-separated. The business themes this property covers.</small>
                            </div>

                            <div class="field">
                                <label for="seo-regions">Target regions</label>
                                <InputText id="seo-regions" v-model="seo.profileForm.regions" placeholder="United Arab Emirates:AE, Saudi Arabia:SA" fluid />
                                <small>Comma-separated <code>Name:CODE</code> pairs. Code is the 2-letter country code.</small>
                            </div>

                            <div class="field">
                                <label for="seo-brand">Brand terms</label>
                                <InputText id="seo-brand" v-model="seo.profileForm.brand_terms" placeholder="ghassan aboud, aboud cars" fluid />
                                <small>Comma-separated. Used to separate brand from non-brand opportunities.</small>
                            </div>

                            <div class="field">
                                <label for="seo-competitors">Known competitors (optional)</label>
                                <Textarea id="seo-competitors" v-model="seo.profileForm.competitor_seeds" rows="2" placeholder="competitor1.com, competitor2.com" fluid />
                                <small>Comma-separated bare domains, used later for competitor research.</small>
                            </div>

                            <div>
                                <Button
                                    type="submit"
                                    label="Save profile"
                                    icon="pi pi-check"
                                    :loading="seo.saveProfile.saving.value"
                                />
                            </div>
                        </form>
                    </article>
                </template>
            </template>
        </div>
    </AsyncState>
</template>

<style scoped>
.ml-1 { margin-left: 4px; }
.plan-summary { margin: 0 0 12px; line-height: 1.5; }

.plan-items { display: flex; flex-direction: column; gap: 12px; }
.plan-item {
    border: 1px solid var(--surface-border, #e5e7eb);
    border-radius: 10px;
    padding: 14px 16px;
}
.plan-item-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 0;
    background: none;
    border: 0;
    cursor: pointer;
    text-align: left;
}
.plan-item-chevron { font-size: 12px; color: var(--text-color-secondary, #6b7280); }
.plan-item-title { margin-left: 4px; }
.plan-item-body { margin-top: 10px; }
.plan-item-rationale { margin: 0 0 6px; }
.plan-item-meta { display: flex; flex-wrap: wrap; gap: 16px; font-size: 13px; margin-bottom: 6px; }
.plan-item.open { border-color: var(--primary-color, #0d9488); }
.plan-rec-body {
    white-space: pre-wrap;
    line-height: 1.5;
    font-size: 13px;
    margin-top: 8px;
    padding: 12px;
    border-radius: 8px;
    background: var(--surface-100, #f8fafc);
    overflow-x: auto;
}
</style>
