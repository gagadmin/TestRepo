<?php

/*
|--------------------------------------------------------------------------
| SEO AI insights configuration
|--------------------------------------------------------------------------
|
| Tunable thresholds, scoring weights and the position→CTR baseline curve for
| the SEO insights feature. Kept here so an administrator can adjust behaviour
| without code changes. See docs/seo-ai-page-architecture.md.
|
*/

return [
    // A keyword must clear this many impressions before it is treated as a real
    // opportunity (filters statistical noise from tiny properties).
    'min_impressions' => (int) env('SEO_MIN_IMPRESSIONS', 50),
    'min_country_impressions' => (int) env('SEO_MIN_COUNTRY_IMPRESSIONS', 100),

    // "Almost there" band — positions worth pushing toward the Top 5.
    'position_band' => [
        'from' => (float) env('SEO_BAND_FROM', 6),
        'to' => (float) env('SEO_BAND_TO', 20),
    ],

    // A CTR below expected * (1 - tolerance) is flagged as an underperformer.
    'ctr_gap_tolerance' => (float) env('SEO_CTR_GAP_TOLERANCE', 0.4),

    // Phase 2 (trends): how much a position must worsen to count as a decline.
    'decline_threshold' => (float) env('SEO_DECLINE_THRESHOLD', 1.5),
    'snapshot_retention_days' => (int) env('SEO_SNAPSHOT_RETENTION_DAYS', 180),
    // Minimum age of the baseline snapshot when comparing period-over-period.
    // Defaults to the window length so "now" and "baseline" don't overlap.
    'comparison_gap_days' => (int) env('SEO_COMPARISON_GAP_DAYS', 28),
    // Dimensions captured nightly for trend analysis.
    'snapshot_dimensions' => ['query', 'page'],

    // How many keywords appear in the headline "closest to Top 5" list.
    'top_opportunities' => (int) env('SEO_TOP_OPPORTUNITIES', 12),

    // Action-plan generation needs its own, larger output budget: reasoning
    // models spend hidden tokens before emitting the JSON, so the default
    // ai.max_output_tokens (1800) truncates the items array. Keep reasoning
    // light for this structured task.
    'plan_max_output_tokens' => (int) env('SEO_PLAN_MAX_OUTPUT_TOKENS', 6000),
    'plan_reasoning_effort' => env('SEO_PLAN_REASONING_EFFORT', 'low'),

    // Default reporting window (days back from the freshest complete GSC day).
    'window_days' => (int) env('SEO_WINDOW_DAYS', 28),

    // Short cache for computed insights, keyed by source + window + access scope.
    'cache_seconds' => (int) env('SEO_CACHE_SECONDS', 900),

    /*
     * Opportunity score weights (must be interpreted relative to each other;
     * they need not sum to 1). `trend` is 0 until Phase 2 stores history;
     * `difficulty` is 0 until a keyword-difficulty source exists.
     */
    'scoring' => [
        'proximity' => (float) env('SEO_W_PROXIMITY', 0.35),
        'demand' => (float) env('SEO_W_DEMAND', 0.25),
        'ctr_headroom' => (float) env('SEO_W_CTR_HEADROOM', 0.25),
        'trend' => (float) env('SEO_W_TREND', 0.15),
        'difficulty' => (float) env('SEO_W_DIFFICULTY', 0.0),
    ],

    // log10(impressions) is normalised against this reference (10^ref → 1.0).
    'demand_log_reference' => (float) env('SEO_DEMAND_LOG_REFERENCE', 4.0),

    /*
     * Baseline organic CTR by integer position (fraction, not %). Industry
     * approximation; refine later from the property's own aggregate data.
     * Positions beyond 20 fall back to the position-20 value.
     */
    'ctr_curve' => [
        1 => 0.285, 2 => 0.157, 3 => 0.094, 4 => 0.064, 5 => 0.049,
        6 => 0.037, 7 => 0.029, 8 => 0.024, 9 => 0.020, 10 => 0.018,
        11 => 0.016, 12 => 0.014, 13 => 0.013, 14 => 0.012, 15 => 0.011,
        16 => 0.010, 17 => 0.010, 18 => 0.009, 19 => 0.009, 20 => 0.008,
    ],
];
