<script setup>
/**
 * A single metric tile.
 *
 * App.vue contained three separate KPI implementations (`itsm-kpi-grid`,
 * `security-kpi`, `metric-grid`) with duplicated markup. This is the one.
 */
import { computed } from 'vue';
import { formatNumber } from '@/composables/useFormatters';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String, null], default: null },
    /** neutral | critical | warning | good */
    tone: { type: String, default: 'neutral' },
    icon: { type: String, default: '' },
    /** Pass false for pre-formatted strings such as percentages or durations. */
    format: { type: Boolean, default: true },
    trend: { type: String, default: '' },
    hint: { type: String, default: '' },
});

const displayValue = computed(() => (
    props.format ? formatNumber(props.value) : (props.value ?? '—')
));

const toneClass = computed(() => ({
    critical: 'kpi-critical',
    warning: 'kpi-warning',
    good: 'kpi-good',
}[props.tone] ?? ''));

const trendIcon = computed(() => ({
    up: 'pi-arrow-up-right',
    down: 'pi-arrow-down-right',
}[props.trend] ?? ''));
</script>

<template>
    <article :class="['panel', 'kpi-card', toneClass]">
        <i v-if="props.icon" :class="['pi', props.icon]" aria-hidden="true"></i>
        <span>{{ props.label }}</span>
        <strong>
            {{ displayValue }}
            <i
                v-if="trendIcon"
                :class="['pi', trendIcon, `kpi-trend-${props.trend}`]"
                aria-hidden="true"
            ></i>
        </strong>
        <!-- Trend direction is also stated in text: colour alone is not accessible. -->
        <small v-if="props.hint">{{ props.hint }}</small>
    </article>
</template>

<style scoped>
.kpi-card {
    display: grid;
    gap: 5px;
    padding: 15px 16px;
    min-width: 0;
}

.kpi-card span {
    color: var(--muted);
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
}

.kpi-card strong {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--ink);
    font-size: 24px;
    line-height: 1.1;
}

.kpi-card small {
    color: var(--muted);
    font-size: 10px;
    line-height: 1.5;
}

.kpi-card > .pi:first-child {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    color: var(--teal-dark);
    border-radius: 9px;
    background: var(--mint);
}

.kpi-critical strong { color: #a43c35; }
.kpi-warning strong { color: #9a6419; }
.kpi-good strong { color: #2f7d68; }
.kpi-critical > .pi:first-child { color: #a43c35; background: #fff0ef; }
.kpi-warning > .pi:first-child { color: #9a6419; background: #fff6e6; }

.kpi-trend-up { color: #a43c35; font-size: 12px; }
.kpi-trend-down { color: #2f7d68; font-size: 12px; }
</style>
