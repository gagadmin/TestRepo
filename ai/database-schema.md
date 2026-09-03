# Database Schema

## Overview

Laravel migrations are the authoritative schema definition. PostgreSQL is the intended shared-environment database; MySQL is also supported by explicit migration branches, and PHPUnit uses in-memory SQLite.

## Identity and framework tables

| Table | Purpose and important fields |
| --- | --- |
| `users` | Name, unique email, department, access profile (`allowed_departments`, `allowed_data_source_ids`), title, active flag, last login, password |
| `roles` | Unique machine name, label, description |
| `permissions` | Unique machine name, label, group |
| `role_user` | Composite-key user-to-role assignment |
| `permission_role` | Composite-key role-to-permission assignment |
| `sessions` | Database-backed browser sessions |
| `password_reset_tokens` | Standard Laravel password reset tokens |
| `cache`, `cache_locks` | Database cache and locks |
| `jobs`, `job_batches`, `failed_jobs` | Database queue state |

`users.department` is the current department catalog and assignment mechanism: it is a normalized nullable string maintained from Users & Access. Existing values are offered as suggestions, while a new value can be entered without a separate department record. Department values participate in dashboard, report, and data-source visibility; they do not automatically create dashboard definitions.

### User access profile

Two nullable JSON columns hold the administrator-configured data visibility of an account. Both narrow visibility on top of role permissions and never widen it; administrators bypass both.

| Column | Meaning |
| --- | --- |
| `users.allowed_departments` | Departments whose department-scoped dashboards and reports the user may see. Empty or null falls back to the single `users.department` label, which preserves the behaviour of accounts created before the profile existed. |
| `users.allowed_data_source_ids` | Per-user platform allow list. `null` means no per-user platform restriction, so access follows `data_sources.settings.allowed_roles`/`allowed_departments` alone. An array restricts the user to those sources, and `[]` therefore permits none. A source owner and any administrator are unaffected. |

`User::accessibleDepartments()`, `User::canViewDepartment()`, and `User::restrictedDataSourceIds()` are the only readers of these columns. `Dashboard::scopeVisibleTo`, `Report::scopeVisibleTo`, and `DataSource::isAccessibleBy` consume them, so every route that already used those gates inherits the profile.

Migration `2026_09_03_000100_add_user_access_profile_columns` is additive and backfills `allowed_departments` with each existing `department` value, so the release does not change any user effective visibility.

## Integration tables

| Table | Key columns | Relationships |
| --- | --- | --- |
| `data_sources` | name, type, base URL, status, settings, last tested | Optional owner user; one API configuration; many runs |
| `api_configurations` | auth type, encrypted credentials/headers, timeout, retries | Belongs to and cascades with data source |
| `integration_runs` | operation, status, HTTP status, duration, safe context, timestamps | Belongs to source; optional initiating user |

`data_sources.status`, `integration_runs.operation`, and `integration_runs.status` are indexed. Credentials and custom headers are encrypted through model casts.

## AI and conversation tables

| Table | Key columns | Relationships |
| --- | --- | --- |
| `conversations` | encrypted title, status, last message time | Belongs to user; many messages and tool executions |
| `messages` | role, provider, model, response ID, encrypted content/tool calls/citations/metadata, token and latency metrics | Belongs to conversation |
| `ai_tool_executions` | tool/call IDs, encrypted arguments/result summary/citations, status, duration, safe error data | Belongs to conversation and user; optional message |

Conversation deletion cascades to messages and tool executions. A deleted message nulls its execution link. Tool names, call IDs, and statuses are indexed.

## Reporting tables

| Table | Key columns | Relationships |
| --- | --- | --- |
| `reports` | owner, name, type, definition, visibility, last generated | Belongs to user; many snapshots, schedules, dashboards, insights |
| `report_snapshots` | encrypted data/summary/citations, row count, generated time | Belongs to report; optional generator |
| `dashboards` | unique slug, department, visibility, layout, active flag | Many-to-many reports |
| `dashboard_report` | sort order, widget size, settings | Composite-key dashboard/report pivot |

Report type and visibility are indexed. Snapshot lookup uses a composite `(report_id, generated_at)` index. Snapshot business payloads are stored as encrypted text after migration `000600`.

Migration `2026_07_29_000100` provisions one department-visible `itsm_ticket_summary` report for each connected Freshservice source present during deployment and links it to the ITSM dashboard. Its definition stores only the source ID, aggregate column/chart metadata, visibility allow lists, and a provisioning marker; credentials and raw ticket payloads are not copied.

## Scheduling tables

| Table | Key columns | Relationships |
| --- | --- | --- |
| `report_schedules` | frequency/cron, timezone, format, filters, channels, encrypted recipients, next/last run, failure state | Belongs to report and creator; many runs |
| `report_schedule_runs` | status, trigger, channel results, error, timing | Belongs to schedule; optional report, triggering user, and snapshot |

`next_run_at` and run status are indexed. Run history has a composite `(report_schedule_id, created_at)` index.

## Analytics tables

| Table | Key columns | Relationships |
| --- | --- | --- |
| `analytics_insights` | batch UUID, type, severity, metric, title, narrative, encrypted payload, generated time | Belongs to report and snapshot; optional generator |

Batch, type, severity, metric, generated time, and `(report_id, generated_at)` are indexed.

## Deletion behavior

- User-owned conversations and reports cascade when their user is deleted.
- A deleted source cascades its API configuration and integration history, but application logic blocks deletion while a report still references the source definition.
- Reports cascade snapshots, schedules, dashboard pivots, and insights.
- Dashboard pivots cascade with either parent.
- Historical references to optional actors generally become null.

## Encryption and key management

Sensitive fields depend on Laravel's `APP_KEY`. Rotating the key without retaining `APP_PREVIOUS_KEYS` can make existing encrypted records unreadable. Back up keys through the deployment secret manager and test key rotation/restoration before production use.

Encrypted application fields include integration secrets and headers, conversation content and metadata, tool arguments/results/citations, report snapshot payloads, schedule recipients, and analytics payloads. Passwords remain one-way hashed rather than encrypted.

## Schema change rules

- Add a forward migration; do not edit a migration already applied to a shared environment.
- Define foreign-key delete behavior explicitly.
- Add indexes for verified query patterns, not speculatively.
- Test migrations with PostgreSQL before release because the automated suite uses SQLite and encrypted-column migrations contain database-specific SQL.
