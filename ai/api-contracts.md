# API Contracts

## Conventions

- Base origin: the Laravel application origin; there is no separate API host.
- Authentication: Laravel session cookie.
- CSRF: Axios sends the `X-XSRF-TOKEN` value issued by Laravel.
- Content type: JSON for API requests and responses, except export downloads.
- Authorization failures: `401` when unauthenticated and `403` when authenticated without permission/access.
- Validation failures: Laravel `422` JSON error structure.
- Resource IDs are numeric unless noted; dashboard identifiers are slugs.
- APIs are currently unversioned and defined in `routes/web.php`.

## Authentication and bootstrap

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| `POST` | `/auth/login` | Guest, throttled | Start a session |
| `POST` | `/auth/logout` | Authenticated active user | End the session |
| `GET` | `/api/bootstrap` | Authenticated active user | User, permissions, navigation, and platform summary |
| `GET` | `/api/admin/users` | `users.view` | Paginated safe user listing with role and department options |
| `PUT` | `/api/admin/users/{user}` | `users.manage` | Update identity fields, department, roles, and active status |
| `GET` | `/api/admin/audit` | `audit.view` | Paginated, filterable audit evidence |

Administrative user updates reject self-deactivation, self-demotion, and removal of the final active administrator. Departments are normalized user attributes, not separately provisioned records: an administrator can enter an existing or new department value while editing a user. Every access update records a safe before/after audit event.

Audit results are limited to 50 records per page and can be filtered by bounded event text and date range. They include actor identity and audit metadata but never expose credentials or decrypted configuration.

## Integrations

All integration routes require `integrations.manage`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/integrations` | List safe source metadata and supported options |
| `POST` | `/api/integrations` | Create a source and encrypted API configuration |
| `POST` | `/api/integrations/search-console/test` | Test read-only Search Console authentication and configured-property access |
| `PUT` | `/api/integrations/{dataSource}` | Update configuration or rotate credentials |
| `POST` | `/api/integrations/{dataSource}/test` | Run and record a governed health test |
| `GET` | `/api/integrations/{dataSource}/preview` | Preview bounded Google Search Console metrics for a connected Search Console source |
| `DELETE` | `/api/integrations/{dataSource}` | Delete an unused source |

Credentials and raw headers are never returned. Boolean flags indicate whether stored secrets exist.

For `google_search_console` create/update requests, `settings.site_url` is the exact property identifier. Bare URL-prefix origins are canonicalized with a trailing slash to match Search Console's property identifier. Generic connector fields `settings.health_path` and `settings.data_path` are ignored and removed because the dedicated server-side connector owns the Google API endpoints.

The Search Console test is throttled to five requests per minute. Its `result` contains success, a safe message, upstream HTTP status, error code, duration, and an allow-listed context containing only property/access or Google status/reason fields. It never returns credential paths, service-account keys, OAuth assertions, access tokens, or raw Google response bodies.

Search Console preview is throttled to ten requests per minute and accepts optional `date_from`, `date_to`, `dimension` (`query`, `page`, `country`, `device`, or `date`), and `limit` (maximum 200). The source must be connected and visible to the caller. Results contain normalized clicks, impressions, CTR percentage points, average position, a bounded row list, and source citation metadata.

## AI conversations

All routes require `ai.chat`; chat is throttled to 20 requests per minute.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/ai/status` | Provider readiness and safe configuration |
| `GET` | `/api/ai/conversations` | Current user's conversation list |
| `GET` | `/api/ai/conversations/{conversation}` | Owned conversation with messages/tool evidence |
| `DELETE` | `/api/ai/conversations/{conversation}` | Delete an owned conversation |
| `POST` | `/api/ai/chat` | Ask a question, optionally continuing a conversation |

The server validates conversation ownership and tool arguments. Responses include citations and safe tool activity, not raw integration credentials.

Provider failures return a safe `message`. Classified provider failures also include `error_code` and `retryable`. OpenAI `insufficient_quota` returns `503` and is not retried; transient OpenAI or Google AI Studio rate limits return `429` after bounded retries.

## Dashboards and reports

Dashboard routes require `dashboards.view`. Report routes require `reports.view`; mutations also require `reports.create`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/dashboards` | List visible active dashboards |
| `GET` | `/api/dashboards/search-console` | Retrieve an authorized, bounded Search Console dashboard breakdown |
| `GET` | `/api/dashboards/freshservice` | Retrieve authorized, bounded Freshservice ticket and SLA analytics |
| `GET` | `/api/dashboards/{slug}` | Dashboard layout, reports, widgets, and snapshot data |
| `GET` | `/api/reports` | List reports visible to the current user |
| `POST` | `/api/reports` | Create a reusable report definition |
| `GET` | `/api/reports/{report}` | Get an authorized report and latest snapshot |
| `PUT` | `/api/reports/{report}` | Update an authorized report |
| `POST` | `/api/reports/{report}/generate` | Generate and store a snapshot; throttled |
| `GET` | `/api/reports/{report}/export/{format}` | Download `pdf` or `xlsx`; throttled |

Report visibility is enforced in model scopes and controller checks. Report definitions select a supported report type, source, endpoint, filters, columns, and chart configuration. The `itsm_ticket_summary` type is restricted to Freshservice sources and converts the governed aggregate analytics into exportable report rows.

The Search Console dashboard endpoint accepts `data_source_id`, `dimension`, optional dates, and an optional limit up to 100. It requires `dashboards.view`, rechecks source visibility and connected status, and supports query, page, country, device, and date breakdowns without exposing the service-account credential.

The Freshservice dashboard endpoint accepts `data_source_id` and optional ticket-created `date_from`/`date_to` filters. It requires `dashboards.view`, rechecks source visibility and connected status, and returns aggregate counts only: current ticket summary, overdue/due-today/open/on-hold/unassigned totals, unresolved tickets by priority/status/agent, and SLA breaches by group and agent. It discovers standard and custom statuses through `/api/v2/ticket_form_fields`. Source setting `on_hold_status_ids` identifies statuses whose SLA timer is disabled; those tickets are counted as on hold and excluded from overdue/due-today calculations. Ticket and directory requests are page-bounded and response-size limited.

## Scheduled reports

All routes require `reports.schedule`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/schedules` | List accessible schedules and recent runs |
| `POST` | `/api/schedules` | Create a schedule |
| `PUT` | `/api/schedules/{schedule}` | Update an accessible schedule |
| `DELETE` | `/api/schedules/{schedule}` | Delete an accessible schedule |
| `POST` | `/api/schedules/{schedule}/run` | Queue an immediate run; throttled |

Schedules accept a frequency, optional five-part cron expression, timezone, export format, filters, delivery channels, and recipients. A source-backed report may be scheduled before its first manual refresh; the queued job generates a fresh snapshot at delivery time. Email needs at least one recipient; Teams needs a configured webhook. Freshservice ITSM report emails include the current unresolved, SLA, due-today, and on-hold headline metrics plus aggregate ticket, priority, group/agent, and agent workload tables; the full aggregate rows are also attached as PDF or Excel.

## Advanced analytics

Listing requires `analytics.view`; generation also requires `analytics.run`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/analytics` | List recent visible insight batches |
| `POST` | `/api/analytics/reports/{report}` | Generate governed insights from an authorized snapshot |

## Non-API routes

- `GET /` and the fallback route render the SPA.
- `GET /up` is Laravel's health endpoint.
- Report download responses are binary attachments rather than JSON.

## Contract-change procedure

There is no OpenAPI schema or consumer contract test yet. When changing a route:

1. Update its Form Request/controller tests.
2. Update this file and any UI caller.
3. Review the generated Postman synchronization workflow output.
4. Manually correct grouped route prefixes or multiline declarations that diff-based extraction cannot infer.
