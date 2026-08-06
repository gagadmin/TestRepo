<?php

return [
    'allow_private_networks' => (bool) env('INTEGRATION_ALLOW_PRIVATE_NETWORKS', false),
    'require_https' => (bool) env('INTEGRATION_REQUIRE_HTTPS', true),

    'types' => [
        'crm' => ['label' => 'CRM', 'icon' => 'pi-users'],
        'erp' => ['label' => 'ERP', 'icon' => 'pi-building'],
        'sap' => ['label' => 'SAP', 'icon' => 'pi-box'],
        'asset_management' => ['label' => 'Asset Management', 'icon' => 'pi-warehouse'],
        'procurement' => ['label' => 'Procurement', 'icon' => 'pi-shopping-cart'],
        'website_analytics' => ['label' => 'Website Analytics', 'icon' => 'pi-chart-line'],
        'google_search_console' => ['label' => 'Google Search Console', 'icon' => 'pi-google'],
        'freshservice' => ['label' => 'Freshservice ITSM', 'icon' => 'pi-ticket'],
        'internal_application' => ['label' => 'Internal Application', 'icon' => 'pi-server'],
    ],

    'freshservice' => [
        'max_ticket_pages' => (int) env('FRESHSERVICE_MAX_TICKET_PAGES', 10),
        'max_directory_pages' => (int) env('FRESHSERVICE_MAX_DIRECTORY_PAGES', 5),
        'max_response_bytes' => (int) env('FRESHSERVICE_MAX_RESPONSE_BYTES', 5_000_000),
    ],
];
