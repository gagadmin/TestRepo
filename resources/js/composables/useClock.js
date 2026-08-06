import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A ticking clock with greeting and formatted date.
 *
 * Extracted from App.vue, where the interval id lived in a module-scoped
 * `let clockIntervalId` — meaning it was shared across every instance and
 * leaked if the component ever mounted twice. Scoping it to the composable
 * fixes that.
 *
 * @param {number} [intervalMs] How often to tick. One minute is enough for a
 *        greeting and a date; a one-second tick would re-render for nothing.
 */
export function useClock(intervalMs = 60_000) {
    const now = ref(new Date());
    let intervalId = null;

    onMounted(() => {
        now.value = new Date();
        intervalId = window.setInterval(() => {
            now.value = new Date();
        }, intervalMs);
    });

    onBeforeUnmount(() => {
        if (intervalId !== null) {
            window.clearInterval(intervalId);
            intervalId = null;
        }
    });

    const greeting = computed(() => {
        const hour = now.value.getHours();

        if (hour < 12) return 'Good morning';
        if (hour < 18) return 'Good afternoon';

        return 'Good evening';
    });

    const longDate = computed(() => new Intl.DateTimeFormat(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(now.value));

    return { now, greeting, longDate };
}

export default useClock;
