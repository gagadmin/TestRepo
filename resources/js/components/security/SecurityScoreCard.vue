<script setup>
/**
 * Overall security score with its deduction breakdown.
 *
 * The breakdown is shown rather than hidden: a score nobody can explain is a
 * score nobody trusts or acts on.
 */
import Tag from 'primevue/tag';
import { scoreSeverity } from '@/composables/useFormatters';

const props = defineProps({
    score: { type: Number, required: true },
    grade: { type: String, required: true },
    breakdown: { type: Array, default: () => [] },
});
</script>

<template>
    <article class="panel security-score-card">
        <p class="eyebrow">Overall security score</p>

        <div class="security-score-value">
            <strong>{{ props.score }}</strong>
            <span>/ 100</span>
            <Tag :severity="scoreSeverity(props.score)" :value="`Grade ${props.grade}`" />
        </div>

        <div
            class="security-score-bar"
            role="progressbar"
            :aria-valuenow="props.score"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="`Security score ${props.score} of 100, grade ${props.grade}`"
        >
            <span :style="{ width: `${props.score}%` }"></span>
        </div>

        <ul class="security-score-breakdown">
            <li v-for="item in props.breakdown" :key="item.reason">
                <span>
                    {{ item.reason }}<template v-if="item.count"> ({{ item.count }})</template>
                </span>
                <strong>{{ item.points }}</strong>
            </li>
            <li v-if="!props.breakdown.length" class="security-score-clean">
                <span>No deductions — all checks passing.</span>
            </li>
        </ul>
    </article>
</template>
