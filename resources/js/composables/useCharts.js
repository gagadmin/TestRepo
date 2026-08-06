/**
 * ApexCharts option factories.
 *
 * App.vue defined `itsmPieOptions`, `itsmBarOptions`, `chartOptions`,
 * `slaCategoryChartOptions`, `ageingChartOptions`, `agentMatrixChartOptions`,
 * `securityTrendChartOptions`, `severityChartOptions`, `complianceChartOptions`
 * and `authChartOptions` — ten near-identical objects that each re-declared the
 * same font family, toolbar, palette and tooltip formatting.
 *
 * These builders share one theme so every chart in the product looks the same,
 * and a palette change happens in one place.
 */

export const CHART_PALETTE = [
    '#19a7a0', '#143d3a', '#f2aa4c', '#657b76',
    '#d05c5c', '#78c6b8', '#9a6419', '#4c7ba8',
];

export const SEVERITY_PALETTE = ['#b42318', '#d05c5c', '#f2aa4c', '#4c7ba8', '#657b76'];
export const SLA_PALETTE = ['#d05c5c', '#19a7a0'];

const BASE = {
    fontFamily: 'Inter, sans-serif',
    toolbar: { show: false },
};

const countTooltip = (unit) => ({
    y: { formatter: (value) => `${Number(value).toLocaleString()} ${unit}` },
});

const wholeNumberLabels = {
    formatter: (value) => Math.round(value).toLocaleString(),
};

/** Donut / pie from `[{ label, value }]`. */
export function pieOptions(items = [], { palette = CHART_PALETTE, emptyText } = {}) {
    return {
        chart: { ...BASE },
        colors: palette,
        labels: items?.map((item) => item.label) ?? [],
        legend: { position: 'bottom', fontSize: '11px' },
        dataLabels: { enabled: true },
        stroke: { colors: ['#fff'] },
        noData: { text: emptyText ?? 'No data available.' },
    };
}

export function pieSeries(items = []) {
    return items?.map((item) => Number(item.value ?? 0)) ?? [];
}

/** Horizontal bar from `[{ label, value }]`. */
export function barOptions(items = [], {
    labelKey = 'label',
    unit = 'records',
    emptyText,
    palette = ['#19a7a0'],
} = {}) {
    return {
        chart: { ...BASE },
        colors: palette,
        dataLabels: { enabled: false },
        plotOptions: { bar: { horizontal: true, borderRadius: 5 } },
        xaxis: {
            categories: items?.map((item) => item[labelKey]) ?? [],
            labels: wholeNumberLabels,
        },
        yaxis: { labels: { maxWidth: 240, style: { fontSize: '11px' } } },
        tooltip: countTooltip(unit),
        noData: { text: emptyText ?? 'No data available.' },
    };
}

export function barSeries(items = [], name = 'Total') {
    return [{ name, data: items?.map((item) => Number(item.value ?? 0)) ?? [] }];
}

/** Stacked horizontal bar, used for the SLA and agent-status breakdowns. */
export function stackedBarOptions(categories = [], {
    unit = 'tickets',
    axisTitle = null,
    palette = SLA_PALETTE,
    emptyText,
} = {}) {
    return {
        chart: { ...BASE, type: 'bar', stacked: true },
        colors: palette,
        plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '70%' } },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px' },
        xaxis: {
            categories,
            title: axisTitle
                ? { text: axisTitle, style: { fontSize: '11px', fontWeight: 500 } }
                : undefined,
            labels: wholeNumberLabels,
        },
        yaxis: { labels: { maxWidth: 260, style: { fontSize: '11px' } } },
        tooltip: { shared: true, intersect: false, ...countTooltip(unit) },
        noData: { text: emptyText ?? 'No data for the selected filters.' },
    };
}

/** Stacked vertical column, used for ageing bands. */
export function stackedColumnOptions(categories = [], {
    unit = 'tickets',
    yAxisTitle = null,
    palette = SLA_PALETTE,
    emptyText,
} = {}) {
    return {
        chart: { ...BASE, type: 'bar', stacked: true },
        colors: palette,
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 5 } },
        dataLabels: { enabled: false },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px' },
        xaxis: { categories },
        yaxis: {
            title: yAxisTitle
                ? { text: yAxisTitle, style: { fontSize: '11px', fontWeight: 500 } }
                : undefined,
            labels: wholeNumberLabels,
        },
        tooltip: { shared: true, intersect: false, ...countTooltip(unit) },
        noData: { text: emptyText ?? 'No data available.' },
    };
}

/** Smoothed area chart, used for the security activity trend. */
export function areaOptions(categories = [], { palette = ['#d05c5c', '#f2aa4c'], emptyText } = {}) {
    return {
        chart: { ...BASE, type: 'area', stacked: false },
        colors: palette,
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.05 } },
        legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px' },
        xaxis: {
            categories,
            labels: { rotate: -45, style: { fontSize: '10px' } },
            tickAmount: 10,
        },
        yaxis: { labels: wholeNumberLabels },
        noData: { text: emptyText ?? 'No activity for this period.' },
    };
}

/** Percentage bar, used for compliance-by-framework. */
export function percentageBarOptions(categories = [], { emptyText } = {}) {
    return {
        chart: { ...BASE },
        colors: ['#19a7a0'],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
        dataLabels: {
            enabled: true,
            formatter: (value) => `${value}%`,
            style: { fontSize: '11px' },
        },
        xaxis: {
            categories,
            max: 100,
            labels: { formatter: (value) => `${Math.round(value)}%` },
        },
        noData: { text: emptyText ?? 'No compliance data.' },
    };
}

/** Row height for horizontal bar charts so labels never collide. */
export function barChartHeight(rowCount, { minimum = 310, perRow = 34 } = {}) {
    return Math.max(minimum, (rowCount ?? 0) * perRow);
}

export function useCharts() {
    return {
        pieOptions,
        pieSeries,
        barOptions,
        barSeries,
        stackedBarOptions,
        stackedColumnOptions,
        areaOptions,
        percentageBarOptions,
        barChartHeight,
        CHART_PALETTE,
        SEVERITY_PALETTE,
        SLA_PALETTE,
    };
}

export default useCharts;
