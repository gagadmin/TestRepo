# Feature Overview

| Capability | Status | Main implementation |
| --- | --- | --- |
| Session authentication | Implemented | Auth controller, login throttling, active-user middleware |
| RBAC | Implemented | Roles, permissions, route middleware, visibility scopes |
| User and access administration | Implemented | Searchable user view, role/department/title assignment, activation controls, self/last-admin protections |
| User access profile | Implemented | Administrator-configured multi-department and per-user platform visibility, consumed by dashboard, report, and data-source gates |
| Permission-based navigation | Implemented | Navigation renders only entries the user may open; restricted routes stay refused server-side |
| Audit trail | Implemented | Filterable administrative view of authentication, mutating-request, export, AI, integration, and access-change records |
| Enterprise sources | Implemented as generic governed HTTP connector | Integration services, encrypted configuration, connection tests |
| Google Search Console connector | Implemented; live query accepted for aboudcar.com and property access verified for gwm.sy | Read-only service-account OAuth, property verification, selectable dashboard explorer, Search Analytics reports, AI tool access, and bounded administrative preview |
| Freshservice ITSM analytics | Implemented; live read verified | Read-only bounded ticket aggregation for operational KPIs, status/priority pies, agent workload, SLA breach views, and schedulable summary reports |
| AI reporting chat | Implemented | Google AI Studio/Gemini, OpenAI, and Azure abstraction with approved read-only tools |
| Conversation history | Implemented | User-owned encrypted conversations/messages |
| Sales report tool | Implemented | `get_sales_report` |
| Asset summary tool | Implemented | `get_asset_summary` |
| Procurement report tool | Implemented | `get_procurement_report` |
| Website analytics tool | Implemented | `get_website_analytics` |
| CRM pipeline tool | Implemented | `get_crm_pipeline` |
| Dashboards | Implemented | Executive and departmental dashboard definitions/widgets |
| Reusable reports | Implemented | Visible report definitions and generation |
| PDF/Excel exports | Implemented | Dompdf and PhpSpreadsheet |
| Scheduled reporting | Implemented | Daily/weekly/monthly/custom schedules, queue jobs |
| Email delivery | Implemented; environment acceptance required | Attached generated export; ITSM deliveries include an inline operational summary |
| Microsoft Teams delivery | Implemented; environment acceptance required | Adaptive card and authenticated report link |
| Advanced analytics | Implemented as deterministic analytics | Trend, robust anomaly, three-period forecast, recommendation |
| Transactional source updates | Out of scope | No write tool or connector path |
| Autonomous approvals/workflows | Roadmap | Not implemented |
| Vendor-specific source adapters | Partially implemented | Google Search Console is first-class; other sources remain generic pending vendor contracts |
| Predictive ML | Roadmap | Current forecast is deterministic, not a trained model |

## Report and dashboard domains

The platform recognizes sales, CRM pipeline, website analytics, asset inventory, procurement spend, executive KPI, financial overview, Freshservice ITSM summary, and custom reports. Dashboard seed/configuration supports Executive, Finance, Sales, Operations, Procurement, Asset Management, Marketing, and Freshservice ITSM views.

## Operational dependencies

AI requires a configured Google AI Studio, OpenAI, or Azure OpenAI endpoint. Enterprise reporting requires at least one authorized and healthy data source. Scheduled reports require the scheduler plus queue workers; delivery requires SMTP and/or a Teams webhook.

Google Search Console verification uses `php artisan search-console:test`. The service account must be granted access to the exact configured URL-prefix or domain property in Search Console, and the Google Cloud project must have the Search Console API enabled. A `google_search_console` data source can then preview and generate website-analytics reports with query, page, country, device, or date metrics.

## Acceptance still needed

Automated tests validate application behavior with fakes and representative fixtures. Production readiness still requires:

- Real source contract and data-quality validation
- Business-owner approval of KPI calculations and masking
- Grounded AI evaluation on representative questions
- Live SMTP and Teams acceptance
- PostgreSQL migration rehearsal
- Normal-load, security, recovery, and user-acceptance testing

Google Search Console has completed a controlled live query for `https://www.aboudcar.com/`, and the service account has verified `siteFullUser` access to `https://gwm.sy/`. GWM analytics content and other enterprise sources still require their own contract and data-quality acceptance.
