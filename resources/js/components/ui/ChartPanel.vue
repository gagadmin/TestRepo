<script setup>
/**
 * Panel wrapper around an ApexCharts instance.
 *
 * Standardises the heading structure that all twenty-odd charts in App.vue
 * repeated by hand, and keeps the chart component itself lazily loaded.
 */
const props = defineProps({
    eyebrow: { type: String, default: '' },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    type: { type: String, default: 'bar' },
    options: { type: Object, required: true },
    series: { type: [Array, Object], required: true },
    height: { type: [Number, String], default: 310 },
    wide: { type: Boolean, default: false },
});
</script>

<template>
    <article :class="['panel', 'itsm-chart-card', { 'itsm-chart-wide': props.wide }]">
        <div class="panel-heading">
            <div>
                <p v-if="props.eyebrow" class="eyebrow">{{ props.eyebrow }}</p>
                <h2>{{ props.title }}</h2>
                <span v-if="props.subtitle" class="muted">{{ props.subtitle }}</span>
            </div>
            <slot name="actions" />
        </div>

        <slot name="before-chart" />

        <apexchart
            :type="props.type"
            :height="props.height"
            :options="props.options"
            :series="props.series"
        />

        <slot name="after-chart" />
    </article>
</template>
