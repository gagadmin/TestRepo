<script setup>
/**
 * Security dashboard page.
 *
 * Composition only: it wires the useSecurityDashboard composable to
 * presentational components. No axios, no metric arithmetic, no formatting
 * logic lives here.
 *
 * Was 594 lines inside App.vue; the behaviour now sits in the composable and
 * the markup in focused child components.
 */
import { onMounted } from 'vue';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';

import AsyncState from '@/components/ui/AsyncState.vue';
import ChartPanel from '@/components/ui/ChartPanel.vue';
import DataTable from '@/components/ui/DataTable.vue';
import KpiCard from '@/components/ui/KpiCard.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SecurityCoverageGrid from '@/components/security/SecurityCoverageGrid.vue';
import SecurityEventTable from '@/components/security/SecurityEventTable.vue';
import SecurityScoreCard from '@/components/security/SecurityScoreCard.vue';
import SecurityTriageDialog from '@/components/security/SecurityTriageDialog.vue';

import { useSecurityDashboard } from '@/composables/useSecurityDashboard';
import { useAuthStore } from '@/stores/authStore';
import {
    ageSeverity, formatDateTime, formatDuration, formatNumber, formatPercentage, severityFor,
} from '@/composables/useFormatters';

const auth = useAuthStore();
const security = useSecurityDashboard();

onMounted(security.load);
</script>

<template>
    <PageHeader
        eyebrow="Restricted · IT and security roles only"
        title="System security"
        description="Continuous monitoring of authentication, access, data movement, and configuration posture."
    >
        <template #actions>
            <Select
                :model-value="security.trendDays.value"
                :options="security.trendOptions"
                option-label="label"
                option-value="value"
                size="small"
                aria-label="Reporting period"
                @update:model-value="security.changeTrend"
            />
            <Button
                label="Refresh"
                icon="pi pi-refresh"
                severity="secondary"
                outlined
                size="small"
                :loading="security.loading.value"
                @click="security.load"
            />
            <Button
                v-if="auth.can('security.manage')"
                label="Run scan"
                icon="pi pi-search"
                size="small"
                :loading="security.scanning.value"
                @click="security.scan"
            />
        </template>
    </PageHeader>

    <Message v-if="security.scanError.value" severity="error" :closable="false">
        {{ security.scanError.value }}
    </Message>

    <AsyncState
        :loading="security.isInitialLoading.value"
        :error="security.error.value"
        loading-text="Loading security posture…"
        @retry="security.load"
        @dismiss-error="security.clearError"
    >
        <div v-if="security.data.value" class="page-stack">
            <nav class="security-tabs" aria-label="Security sections">
                <button
                    v-for="item in security.sections"
                    :key="item.id"
                    type="button"
                    :class="['security-tab', { active: security.section.value === item.id }]"
                    :aria-current="security.section.value === item.id ? 'page' : undefined"
                    @click="security.section.value = item.id"
                >
                    <i :class="['pi', item.icon]" aria-hidden="true"></i>
                    <span>{{ item.label }}</span>
                </button>
            </nav>

            <!-- Overview -->
            <template v-if="security.section.value === 'overview'">
                <section class="security-score-row">
                    <SecurityScoreCard
                        :score="security.data.value.overview.security_score"
                        :grade="security.data.value.overview.score_grade"
                        :breakdown="security.data.value.overview.score_breakdown"
                    />

                    <div class="kpi-grid kpi-grid-4">
                        <KpiCard
                            label="Critical alerts"
                            tone="critical"
                            :value="security.data.value.overview.critical_alerts"
                        />
                        <KpiCard
                            label="High severity"
                            tone="warning"
                            :value="security.data.value.overview.high_alerts"
                        />
                        <KpiCard
                            label="Open incidents"
                            :value="security.data.value.overview.open_incidents"
                        />
                        <KpiCard
                            label="Compliance"
                            tone="good"
                            :format="false"
                            :value="formatPercentage(security.data.value.overview.compliance_percentage)"
                        />
                        <KpiCard
                            label="Mean time to detect"
                            :format="false"
                            :value="formatDuration(security.data.value.overview.mttd_minutes)"
                        />
                        <KpiCard
                            label="Mean time to respond"
                            :format="false"
                            :value="formatDuration(security.data.value.overview.mttr_minutes)"
                        />
                        <KpiCard
                            label="Privileged accounts"
                            :value="security.data.value.identity.privileged_accounts"
                        />
                        <KpiCard
                            label="Findings this period"
                            :value="security.data.value.overview.trend.current_period"
                            :trend="security.data.value.overview.trend.direction"
                            :hint="security.data.value.overview.trend.direction === 'up'
                                ? 'Higher than the previous period'
                                : security.data.value.overview.trend.direction === 'down'
                                    ? 'Lower than the previous period'
                                    : 'Unchanged'"
                        />
                    </div>
                </section>

                <section class="itsm-chart-grid">
                    <ChartPanel
                        wide
                        type="area"
                        eyebrow="Security activity"
                        title="Findings and failed logins over time"
                        :height="300"
                        :options="security.trendChartOptions.value"
                        :series="security.trendSeries.value"
                    />
                    <ChartPanel
                        type="donut"
                        eyebrow="Open findings"
                        title="By severity"
                        :height="300"
                        :options="security.severityChartOptions.value"
                        :series="security.severityChartSeries.value"
                    />
                    <ChartPanel
                        type="donut"
                        eyebrow="Authentication"
                        title="Success vs failure"
                        :height="300"
                        :options="security.authChartOptions.value"
                        :series="security.authChartSeries.value"
                    />
                </section>

                <article v-if="security.data.value.scan" class="panel security-scan-strip">
                    <div>
                        <p class="eyebrow">Last scan</p>
                        <strong>{{ formatDateTime(security.data.value.scan.finished_at) }}</strong>
                        <span class="muted">
                            {{ security.data.value.scan.detectors_run }} detectors ·
                            {{ security.data.value.scan.events_created }} new finding(s) ·
                            {{ security.data.value.scan.duration_seconds }}s
                        </span>
                    </div>
                    <Tag
                        :severity="security.data.value.scan.status === 'succeeded' ? 'success' : 'warn'"
                        :value="security.data.value.scan.status"
                    />
                </article>
            </template>

            <!-- Threats -->
            <template v-else-if="security.section.value === 'threats'">
                <section class="kpi-grid kpi-grid-4">
                    <KpiCard label="Active threats" tone="critical" :value="security.data.value.threats.active_threats" />
                    <KpiCard label="Blocked login attempts" :value="security.data.value.threats.blocked_login_attempts" />
                    <KpiCard label="Distinct sources" :value="security.data.value.threats.suspicious_sources" />
                    <KpiCard label="Sessions revoked" :value="security.data.value.threats.revoked_sessions" />
                </section>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Top attack sources</p>
                            <h2>Failed logins by address</h2>
                        </div>
                    </div>
                    <DataTable
                        :columns="[
                            { key: 'ip_address', label: 'Source address' },
                            { key: 'attempts', label: 'Attempts', numeric: true },
                            { key: 'last_seen', label: 'Last seen' },
                        ]"
                        :rows="security.data.value.threats.top_sources"
                        row-key="ip_address"
                        empty-text="No failed authentication attempts in this period."
                    >
                        <template #cell:ip_address="{ row }"><code>{{ row.ip_address }}</code></template>
                        <template #cell:attempts="{ row }">{{ formatNumber(row.attempts) }}</template>
                        <template #cell:last_seen="{ row }">{{ formatDateTime(row.last_seen) }}</template>
                    </DataTable>
                </article>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Requires review</p>
                            <h2>Open security findings</h2>
                        </div>
                    </div>
                    <SecurityEventTable
                        :events="security.data.value.threats.recent"
                        :can-manage="auth.can('security.manage')"
                        :last-scan-at="security.data.value.scan?.finished_at"
                        @triage="security.openEvent"
                    />
                </article>
            </template>

            <!-- Identity -->
            <template v-else-if="security.section.value === 'identity'">
                <!--
                  Severity follows the actual state: a permanent warning banner
                  when coverage is complete trains people to ignore it.
                -->
                <Message
                    :severity="security.data.value.identity.mfa.coverage_percentage >= 100 ? 'success' : 'warn'"
                    :closable="false"
                >
                    <strong>{{ security.data.value.identity.mfa.note }}</strong>
                </Message>

                <section class="kpi-grid kpi-grid-4">
                    <KpiCard label="Total accounts" :value="security.data.value.identity.total_accounts" />
                    <KpiCard label="Active" tone="good" :value="security.data.value.identity.active_accounts" />
                    <KpiCard label="Privileged" tone="warning" :value="security.data.value.identity.privileged_accounts" />
                    <KpiCard
                        label="MFA coverage"
                        :tone="security.data.value.identity.mfa.coverage_percentage >= 100 ? 'good' : 'critical'"
                        :format="false"
                        :value="formatPercentage(security.data.value.identity.mfa.coverage_percentage)"
                        :hint="`${security.data.value.identity.mfa.enrolled} of ${security.data.value.identity.active_accounts} enrolled`"
                    />
                    <KpiCard label="Dormant" tone="warning" :value="security.data.value.identity.dormant_accounts" />
                    <KpiCard label="Never signed in" :value="security.data.value.identity.never_signed_in" />
                    <KpiCard
                        label="Auth failure rate"
                        tone="critical"
                        :format="false"
                        :value="formatPercentage(security.data.value.identity.authentication.failure_rate)"
                    />
                    <KpiCard
                        label="Active sessions"
                        :format="false"
                        :value="security.data.value.identity.active_sessions.total ?? '—'"
                    />
                    <KpiCard
                        label="Privileged share"
                        :format="false"
                        :value="formatPercentage(security.data.value.identity.privileged_percentage)"
                    />
                </section>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Currently authenticated</p>
                            <h2>Active sessions</h2>
                        </div>
                    </div>
                    <div v-if="!security.data.value.identity.active_sessions.available" class="table-empty">
                        <span>{{ security.data.value.identity.active_sessions.note }}</span>
                    </div>
                    <DataTable
                        v-else
                        :columns="[
                            { key: 'user', label: 'User' },
                            { key: 'department', label: 'Department' },
                            { key: 'ip_address', label: 'Address' },
                            { key: 'user_agent', label: 'Client', truncate: true },
                            { key: 'last_activity', label: 'Last activity' },
                        ]"
                        :rows="security.data.value.identity.active_sessions.sessions"
                        :row-key="(row, index) => `${row.ip_address}-${index}`"
                        empty-text="No active sessions."
                    >
                        <template #cell:department="{ row }">{{ row.department ?? '—' }}</template>
                        <template #cell:ip_address="{ row }"><code>{{ row.ip_address }}</code></template>
                        <template #cell:last_activity="{ row }">{{ formatDateTime(row.last_activity) }}</template>
                    </DataTable>
                </article>
            </template>

            <!-- Incidents -->
            <template v-else-if="security.section.value === 'incidents'">
                <section class="kpi-grid kpi-grid-4">
                    <KpiCard label="Open" tone="critical" :value="security.data.value.incidents.open" />
                    <KpiCard label="Acknowledged" tone="warning" :value="security.data.value.incidents.acknowledged" />
                    <KpiCard label="Resolved this period" tone="good" :value="security.data.value.incidents.resolved" />
                    <KpiCard label="False positives" :value="security.data.value.incidents.false_positive" />
                    <KpiCard label="MTTD" :format="false" :value="formatDuration(security.data.value.incidents.mttd_minutes)" />
                    <KpiCard label="MTTR" :format="false" :value="formatDuration(security.data.value.incidents.mttr_minutes)" />
                </section>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Ageing</p>
                            <h2>Longest open findings</h2>
                        </div>
                    </div>
                    <DataTable
                        :columns="[
                            { key: 'severity', label: 'Severity' },
                            { key: 'title', label: 'Finding', truncate: true },
                            { key: 'age_hours', label: 'Age (hrs)', numeric: true },
                        ]"
                        :rows="security.data.value.incidents.oldest_open"
                        empty-text="No open findings."
                    >
                        <template #cell:severity="{ row }">
                            <Tag
                                :severity="severityFor('securitySeverity', row.severity)"
                                :value="row.severity"
                            />
                        </template>
                    </DataTable>
                </article>
            </template>

            <!-- Compliance -->
            <template v-else-if="security.section.value === 'compliance'">
                <section class="kpi-grid kpi-grid-4">
                    <KpiCard
                        label="Overall compliance"
                        tone="good"
                        :format="false"
                        :value="formatPercentage(security.data.value.compliance.overall_percentage)"
                    />
                    <KpiCard
                        label="Controls passing"
                        :format="false"
                        :value="`${security.data.value.compliance.controls_passed} / ${security.data.value.compliance.controls_total}`"
                    />
                    <KpiCard label="Controls failing" tone="critical" :value="security.failingControlCount.value" />
                </section>

                <ChartPanel
                    class="itsm-chart-standalone"
                    eyebrow="Coverage"
                    title="Controls passed by framework"
                    :height="280"
                    :options="security.complianceChartOptions.value"
                    :series="security.complianceChartSeries.value"
                />

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Control register</p>
                            <h2>Internal security controls</h2>
                        </div>
                    </div>
                    <DataTable
                        :columns="[
                            { key: 'passed', label: 'Status' },
                            { key: 'name', label: 'Control' },
                            { key: 'framework', label: 'Framework' },
                            { key: 'detail', label: 'Detail' },
                        ]"
                        :rows="security.data.value.compliance.controls"
                        row-key="id"
                        max-height="600px"
                    >
                        <template #cell:passed="{ row }">
                            <Tag
                                :severity="row.passed ? 'success' : 'danger'"
                                :value="row.passed ? 'Pass' : 'Fail'"
                            />
                        </template>
                        <template #cell:name="{ row }"><strong>{{ row.name }}</strong></template>
                        <template #cell:detail="{ row }">
                            <span class="security-event-description">{{ row.detail }}</span>
                        </template>
                    </DataTable>
                </article>
            </template>

            <!-- Assets -->
            <template v-else-if="security.section.value === 'assets'">
                <Message severity="info" :closable="false">{{ security.data.value.assets.note }}</Message>

                <section class="kpi-grid kpi-grid-4">
                    <KpiCard label="Integrations" :value="security.data.value.assets.integrations_total" />
                    <KpiCard label="Connected" tone="good" :value="security.data.value.assets.integrations_connected" />
                    <KpiCard label="Not connected" tone="warning" :value="security.data.value.assets.integrations_failing" />
                    <KpiCard label="User accounts" :value="security.data.value.assets.user_accounts" />
                    <KpiCard label="Reports" :value="security.data.value.assets.reports_total" />
                    <KpiCard label="Dashboards" :value="security.data.value.assets.dashboards_total" />
                    <KpiCard label="Scheduled jobs" :value="security.data.value.assets.scheduled_jobs" />
                    <KpiCard label="Encrypted credential sets" :value="security.data.value.assets.integrations_encrypted" />
                </section>

                <article class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">Managed assets</p>
                            <h2>Integration inventory</h2>
                        </div>
                    </div>
                    <DataTable
                        :columns="[
                            { key: 'name', label: 'Name' },
                            { key: 'type', label: 'Type' },
                            { key: 'status', label: 'Status' },
                            { key: 'transport', label: 'Transport' },
                            { key: 'auth_type', label: 'Auth' },
                            { key: 'last_tested_at', label: 'Last tested' },
                        ]"
                        :rows="security.data.value.assets.integrations"
                        empty-text="No integrations registered."
                    >
                        <template #cell:name="{ row }"><strong>{{ row.name }}</strong></template>
                        <template #cell:status="{ row }">
                            <Tag
                                :severity="severityFor('connectionStatus', row.status)"
                                :value="row.status"
                            />
                        </template>
                        <template #cell:transport="{ row }">
                            <Tag
                                :severity="row.transport === 'https' ? 'success' : 'danger'"
                                :value="row.transport"
                            />
                        </template>
                        <template #cell:auth_type="{ row }">{{ row.auth_type ?? '—' }}</template>
                        <template #cell:last_tested_at="{ row }">
                            {{ formatDateTime(row.last_tested_at) }}
                        </template>
                    </DataTable>
                </article>
            </template>

            <!-- Coverage gaps -->
            <template v-else-if="security.section.value === 'coverage'">
                <SecurityCoverageGrid :sections="security.coverageSections.value" />
            </template>

            <footer class="itsm-evidence">
                <span><i class="pi pi-database" aria-hidden="true"></i>{{ security.data.value.meta.data_basis }}</span>
                <span><i class="pi pi-clock" aria-hidden="true"></i>Generated {{ formatDateTime(security.data.value.meta.generated_at) }}</span>
                <span><i class="pi pi-globe" aria-hidden="true"></i>{{ security.data.value.meta.timezone }}</span>
            </footer>
        </div>
    </AsyncState>

    <SecurityTriageDialog
        v-model:visible="security.dialogOpen.value"
        :event="security.activeEvent.value"
        :form="security.form.value"
        :requires-note="security.requiresNote.value"
        :saving="security.saving.value"
        :error="security.saveError.value"
        @submit="security.saveEvent"
    />
</template>
