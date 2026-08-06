<script setup>
/**
 * The four sections that need an external security connector.
 *
 * These render "not connected" and list compatible products. They deliberately
 * show no figures: a fabricated or sample metric on a security dashboard is
 * worse than an honest blank, because it gets screenshotted and believed.
 */
import Message from 'primevue/message';
import Tag from 'primevue/tag';

const props = defineProps({
    sections: { type: Array, default: () => [] },
});
</script>

<template>
    <Message severity="info" :closable="false">
        These areas require an external security data source. Nothing is shown for them
        because no figure would be real until a connector is configured.
    </Message>

    <section class="security-coverage-grid">
        <article
            v-for="section in props.sections"
            :key="section.connector_key"
            class="panel security-coverage-card"
        >
            <div class="security-coverage-head">
                <h2>{{ section.title }}</h2>
                <Tag
                    :severity="section.connected ? 'success' : 'secondary'"
                    :value="section.connected ? 'Connected' : 'Not connected'"
                />
            </div>

            <p class="muted">{{ section.description }}</p>

            <div class="security-coverage-sources">
                <span class="eyebrow">Compatible sources</span>
                <div>
                    <Tag
                        v-for="source in section.suggested_sources"
                        :key="source"
                        severity="secondary"
                        :value="source"
                    />
                </div>
            </div>
        </article>
    </section>
</template>
