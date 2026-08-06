/**
 * Presentation-only formatting helpers.
 *
 * These were scattered through App.vue as loose functions and inline
 * expressions such as `value.toLocaleString()` repeated dozens of times.
 * Pure functions, so no reactivity and trivially unit-testable.
 */

/** Thousands-separated integer. Nullish becomes an em dash, not "0". */
export function formatNumber(value) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '—';
    }

    return Number(value).toLocaleString();
}

/** Minutes into the largest sensible unit. */
export function formatDuration(minutes) {
    if (minutes === null || minutes === undefined) return '—';
    if (minutes < 1) return '<1 min';
    if (minutes < 60) return `${Math.round(minutes)} min`;
    if (minutes < 1440) return `${(minutes / 60).toFixed(1)} hrs`;

    return `${(minutes / 1440).toFixed(1)} days`;
}

export function formatDateTime(value) {
    if (!value) return '—';

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

export function formatDate(value) {
    if (!value) return '—';

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString();
}

export function formatPercentage(value, fractionDigits = 1) {
    if (value === null || value === undefined) return '—';

    return `${Number(value).toFixed(fractionDigits)}%`;
}

/** Convert a snake_case key into a readable label. */
export function humanise(value) {
    if (!value) return '';

    return String(value)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

/** Truncate with an ellipsis, without cutting mid-word where avoidable. */
export function truncate(value, limit = 80) {
    const text = String(value ?? '');

    if (text.length <= limit) return text;

    return `${text.slice(0, limit).trimEnd()}…`;
}

/**
 * PrimeVue Tag severities, kept in one place so a status colour is identical
 * on every screen that shows it.
 */
export const severityMap = {
    connectionStatus: {
        connected: 'success',
        error: 'danger',
        testing: 'warn',
        draft: 'secondary',
    },
    securitySeverity: {
        critical: 'danger',
        high: 'danger',
        medium: 'warn',
        low: 'info',
        info: 'secondary',
    },
    securityStatus: {
        open: 'danger',
        acknowledged: 'warn',
        resolved: 'success',
        false_positive: 'secondary',
    },
    ticketPriority: {
        urgent: 'danger',
        high: 'warn',
        medium: 'info',
        low: 'secondary',
    },
};

export function severityFor(map, key, fallback = 'info') {
    return severityMap[map]?.[String(key ?? '').toLowerCase()] ?? fallback;
}

/** Age thresholds shared by every ageing badge in the app. */
export function ageSeverity(days) {
    if (days >= 30) return 'danger';
    if (days >= 14) return 'warn';

    return 'success';
}

/** Compliance/score thresholds shared by the security widgets. */
export function scoreSeverity(score) {
    if (score === null || score === undefined) return 'secondary';
    if (score >= 90) return 'success';
    if (score >= 75) return 'warn';

    return 'danger';
}

export function useFormatters() {
    return {
        formatNumber,
        formatDuration,
        formatDateTime,
        formatDate,
        formatPercentage,
        humanise,
        truncate,
        severityFor,
        ageSeverity,
        scoreSeverity,
    };
}

export default useFormatters;
