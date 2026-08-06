# Architecture

## System context

```text
Browser
  Vue 3 + PrimeVue + ApexCharts
           |
           | same-origin session, CSRF, JSON
           v
Laravel 12 modular monolith
  Controllers / Requests / Middleware
           |
  +--------+----------------+------------------+
  |                         |                  |
AI orchestration      Reporting/analytics   Integrations
  |                         |                  |
Google AI Studio,     PostgreSQL/MySQL      CRM / ERP / SAP /
OpenAI, or Azure API  + queue + cache       assets / procurement /
                                             web analytics / internal APIs
           |
Scheduled delivery -> SMTP and Microsoft Teams webhook
Exports -> Dompdf and PhpSpreadsheet
```

## Runtime boundaries

- `resources/js/App.vue` is the main SPA component. It uses local view state rather than Vue Router.
- `resources/views/app.blade.php` mounts the Vue application and loads Vite assets.
- Axios uses same-origin cookies and the Laravel XSRF cookie.
- All application and JSON routes currently live in `routes/web.php`, so `/api/*` endpoints use the web/session middleware stack.
- Laravel controllers validate requests, enforce model visibility, and delegate business logic to services.
- Eloquent models persist users, governance records, integration configuration, conversations, reports, snapshots, schedules, and insights.
- Queue jobs generate and deliver scheduled reports. Console schedules dispatch due reports every minute and purge expired snapshots daily.

## Backend modules

| Area | Main components | Responsibility |
| --- | --- | --- |
| Identity | `AuthController`, active/permission middleware, User/Role/Permission models | Session authentication and authorization |
| Platform | `PlatformController`, `AdminUserController` | SPA bootstrap and governed user/role/department administration |
| Integrations | connector registry, generic HTTP connector, Search Console connector, Freshservice analytics service, request factory, URL guard, integration manager | Governed enterprise API access and read-only Google/Freshservice analytics |
| AI | provider manager, Google AI Studio/OpenAI/Azure providers, reporting assistant, tool registry | Natural-language orchestration and allow-listed tool execution |
| Reporting | report controller, data service, export service | Definitions, snapshots, PDF/Excel output |
| Dashboards | dashboard controller and models | Visible dashboard composition and widgets |
| Scheduling | schedule controller, dispatcher command, queue job, delivery service | Timed generation, retries, email, and Teams |
| Analytics | advanced analytics controller/service | Trends, anomalies, forecasts, and recommendations |
| Governance | `AuditLogController`, audit middleware/logs, tool executions, integration runs | Bounded administrative review, traceability, and safe operational evidence |

## Principal request flows

### AI question

1. The authenticated SPA posts a prompt and optional conversation ID.
2. Laravel checks `ai.chat`, validates ownership, and stores an encrypted user message.
3. `ReportingAssistant` invokes the configured provider with governed tool schemas. The Google adapter converts them to Gemini function declarations and preserves required thought signatures across tool rounds.
4. Requested tools are resolved from the allow list, validated, authorized, executed sequentially, and audited.
5. Source output is treated as untrusted data; citations and a bounded answer return to the SPA.
6. The assistant message and metadata are encrypted at rest.

### Report and dashboard

1. The user requests a visible report or dashboard.
2. Laravel applies permission and ownership/visibility scopes.
3. `ReportDataService` fetches a governed source response and applies report filters.
4. A masked, encrypted snapshot is stored.
5. Dashboards render snapshot-derived widgets; exports reuse authorized report data.

For `google_search_console` sources, the reporting gateway uses the dedicated server-side connector instead of a user-configured data path. It exchanges a signed service-account assertion for a read-only OAuth token, queries the exact authorized Search Console property, normalizes metrics, and records source citation metadata. Credential material never enters the browser or data-source record.

Dashboard users can query a connected Search Console source through a purpose-built read-only endpoint. The server rechecks the source's owner/role/department visibility and limits the selectable dimensions and row count; the browser never calls Google directly.

The ITSM dashboard queries a connected Freshservice source through a dedicated read-only analytics service. It discovers ticket status and priority choices, retrieves bounded pages of accessible tickets and directory names, and aggregates only counts by status, priority, agent, and group. Raw ticket subjects, descriptions, requester details, and credentials are not returned to the browser.

### Scheduled delivery

1. The scheduler runs `reports:dispatch-schedules` every minute.
2. Due schedules create run records and queue `GenerateAndDeliverScheduledReport`.
3. The job generates a snapshot/export and sends each configured channel.
4. Channel results, retries, failures, and final status are persisted and audited.

## Security boundaries

- Authentication is Laravel session based; state-changing requests require CSRF protection.
- `active` middleware revokes sessions for deactivated users.
- Named permissions protect functional route groups.
- Integration credentials and sensitive business payloads use Laravel encrypted casts or encrypted text.
- Outbound integrations require HTTPS by default, reject unsafe headers and redirects, and guard against private/reserved destinations unless explicitly allowed.
- AI tools cannot issue arbitrary SQL, arbitrary HTTP requests, writes, shell commands, or code.
- Production CSP restricts scripts and connections to same-origin; local/testing CSP permits Vite development behavior.

## Architectural constraints

- The frontend is concentrated in a 2,000+ line `App.vue`, increasing change coupling.
- API routes are unversioned and session-authenticated inside `web.php`.
- Generic connectors require real vendor contracts before they can normalize pagination and schemas reliably.
- Database queue workers and the Laravel scheduler are required runtime dependencies.
- The deterministic analytics service is not a substitute for a validated predictive model.
