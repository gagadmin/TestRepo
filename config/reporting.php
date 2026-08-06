<?php

return [
    'max_snapshot_rows' => (int) env('REPORT_MAX_SNAPSHOT_ROWS', 1000),
    'dashboard_row_limit' => (int) env('REPORT_DASHBOARD_ROW_LIMIT', 200),
    'snapshot_retention_days' => (int) env('REPORT_SNAPSHOT_RETENTION_DAYS', 365),
    'max_analytics_insights' => (int) env('REPORT_MAX_ANALYTICS_INSIGHTS', 50),
    'analytics_anomaly_threshold' => (float) env('REPORT_ANALYTICS_ANOMALY_THRESHOLD', 3.5),
    'source_types' => [
        'sales' => ['crm', 'erp', 'sap'],
        'crm_pipeline' => ['crm'],
        'website_analytics' => ['website_analytics', 'google_search_console'],
        'asset_inventory' => ['asset_management'],
        'procurement_spend' => ['procurement', 'erp', 'sap'],
        'executive_kpi' => ['erp', 'sap', 'internal_application'],
        'financial_overview' => ['erp', 'sap'],
        'itsm_ticket_summary' => ['freshservice'],
        'custom' => [
            'crm', 'erp', 'sap', 'asset_management', 'procurement',
            'website_analytics', 'google_search_console', 'freshservice', 'internal_application',
        ],
    ],
];
