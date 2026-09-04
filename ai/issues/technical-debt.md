# Technical Debt

## Priority backlog

### TD-001 — Decompose the Vue application

`resources/js/pages/LegacyWorkspacePage.vue` owns navigation, data loading, forms, dashboards, AI chat, reports, schedules, and analytics for the eight views not yet migrated. (This item previously named `App.vue`; that file is now 44 lines and holds only the layout switch — the monolith moved rather than shrank.) Extract feature-oriented components and composables while preserving the current request layer and visual design. Add component tests before moving high-risk state.

**Migration hygiene.** Extracting a view is only finished when its supporting
script leaves `LegacyWorkspacePage.vue` too. The security migration moved the
template into `pages/SecurityPage.vue` but left 236 lines behind — state refs,
a loader, a scan trigger, an event-update call to `/api/security/events`, tag
helpers, and six chart computeds, none of them reachable. Rollup tree-shook all
of it, so the cost was maintenance rather than bundle size, but it read as live
code. When migrating the remaining views, check the extracted symbols for
surviving references before closing the work.

**Adopt the composables rather than copying them.** The same migration left
private copies of `formatDateTime`, `formatDuration`, `ageSeverity`, and the tag
maps in the legacy page, and they drifted from the tested originals in
`useFormatters`: the local `formatDateTime` rendered "Invalid Date" where the
composable returns an em dash, and the local score thresholds were 85/70 against
the shared 90/75, so one score showed two different colours depending on the
screen. The legacy page now imports the shared helpers.

### TD-002 — Establish a versioned API contract

Move stable JSON endpoints toward a versioned API boundary, define OpenAPI schemas, standardize pagination/error envelopes, and add contract validation. Decide explicitly whether future consumers use session, Sanctum, OAuth, or another approved authentication mode.

### TD-003 — Add browser-level testing

Add frontend unit/component tests and a small end-to-end suite for login, AI chat with a fake provider, report generation/export, integration validation, and schedule creation. Include accessibility checks for primary workflows.

### TD-004 — Productionize deployment

Add application-owned deployment definitions, queue supervision, scheduler health, centralized logs, metrics, alerting, backups, recovery exercises, and rollback procedures for the selected hosting environment.

### TD-005 — Validate PostgreSQL continuously

Retain fast SQLite tests, but add a CI job running migrations and integration tests on PostgreSQL. Specifically exercise encrypted column conversion, indexes, locking, scheduler concurrency, and JSON/filter behavior.

### TD-006 — Build vendor-specific connector adapters

The generic connector is a secure foundation, not a complete Salesforce/SAP/ERP contract. Add adapters only after receiving vendor API specifications, with pagination, retry semantics, normalization, data-quality checks, and contract tests.

### TD-007 — Improve observability

Add structured correlation IDs and metrics for AI/tool latency, tokens, connector failures, queue age, schedule lateness, export duration, snapshot volume, and delivery outcomes. Avoid sensitive payloads and high-cardinality identifiers.

**Done: HTTP request correlation.** `AssignCorrelationId` prepends to the `web`
group, assigns a UUID per request, pushes it into the log context so every
subsequent line carries it — including the ones Laravel's exception handler
writes — and returns it as `X-Correlation-Id` so a user can quote it when
reporting a fault. An inbound identifier from a gateway is honoured only if it
matches a short plain-token pattern; the value reaches log files, so an
unvalidated one would let a caller forge log entries. Covered by
`tests/Feature/CorrelationIdTest.php`.

**Done: scheduler and queue correlation.** `App\Support\CorrelationId` is the
single container-bound holder for the current unit of work — request, console
run, or queued job — and `AppServiceProvider` wires the paths outside HTTP: a
console run mints an identifier on `CommandStarting`, `Queue::createPayloadUsing`
writes the dispatching identifier into every job payload, and the worker adopts
it on `JobProcessing`, clearing it on `JobProcessed` and `JobFailed` so one
job's context cannot be attributed to the next. A payload identifier is
validated on the way in exactly as an inbound header is: the payload is data,
not a trusted source, and it reaches the log files. One scheduled sweep and all
the deliveries it queues therefore share a key. Covered by
`tests/Feature/CorrelationPropagationTest.php`.

**Most of the measurements already existed.** An audit on 2026-09-04 found six
of the eight listed items were durable before this item was written, which the
wording obscured: AI latency and tokens in `messages.latency_ms`,
`input_tokens`, `output_tokens` (correctly normalised for all three providers,
including Gemini's `usageMetadata`), tool duration in
`ai_tool_executions.duration_ms`, connector outcomes in `integration_runs`,
delivery outcomes in `report_schedule_runs.channel_results`, and snapshot volume
in `report_snapshots.row_count`.

**Done: queue wait and schedule lateness.** These were the two genuine gaps, and
they are the pair that answer whether anything is running at all — the silent
failure KI-003 describes. `Queue::createPayloadUsing` stamps `queued_at` into
each payload and `JobProcessing` reports `queue_wait_ms`; a payload without the
stamp is skipped rather than mismeasured. `reports:dispatch-schedules` reports
the worst and median lateness against `next_run_at`, captured before the update
that advances it. Median rather than mean, so one long-dormant schedule cannot
distort the figure. Both lines carry the correlation identifier and no payload.
Covered by `tests/Feature/OperationalTelemetryTest.php`.

**Done: export cost.** `ReportExportService` times both writers in one place,
so the interactive download and the scheduled job are measured identically, and
reports format, row count, column count, duration, and output size. Shape only:
a test asserts the exported rows never reach the log line, because those rows
are business data.

**Still outstanding.** There is no metrics backend, so all of the above are log
lines rather than counters or histograms; aggregating them needs the centralised
logging TD-004 calls for, and that is the remaining half of this item. The `/up`
health endpoint is registered outside the `web` group and is deliberately not
correlated.

### TD-008 — Centralize audit policy

Current audit coverage combines middleware and feature-specific records. Define an auditable-event catalog, retention/export policy, immutable storage expectations, and an authorized audit UI.

### TD-010 — Add systematic dependency/security automation

Run Composer and npm vulnerability audits, license review, secret scanning, static analysis, and dependency update checks in CI. Network-restricted local execution should not be the only control.

### TD-011 — Provider retry policy is shared; failure mapping is not

The three AI providers each carried their own copy of the retry loop and an
identical `pauseBeforeRetry`. Azure had already drifted, ignoring the configured
attempt budget and retrying failures that could not succeed. The retry decision
now lives in one trait, `Services/Ai/Providers/Concerns/RetriesProviderRequests`.

Failure *mapping* deliberately remains per-provider: the status codes are common
but the codes, messages, and the question of whether an exhausted quota can be
separated from a rate limit are not. Google folds the two together and marks
them retryable because the Gemini API returns 429 for both; OpenAI and Azure
separate them. That divergence is intentional and is pinned by a test — resolve
it only if Gemini starts reporting the two distinctly.

Exit condition: none outstanding. Recorded so the next person does not "tidy"
the three failure mappings into one and silently change the advice users get.

## Resolved

### TD-009 — Replace seeder `env()` access — resolved 2026-09-04

`DatabaseSeeder` now reads `config('bootstrap.admin.*')` from the new
`config/bootstrap.php` instead of calling `env()`. The material outcome was
larger than the item described: `env()` returns null once `php artisan
config:cache` has run — the normal deployed state, and something this
repository's own deploy step performs — so a cached environment silently fell
back to the development password `ChangeMe123!` rather than the configured one.
The refusal to seed without a configured password also covered `production`
only, leaving staging and UAT able to create an administrator with a credential
published in the source. It now covers every environment except `local` and
`testing`. The hardcoded default administrator email, a named individual's
corporate address, was replaced with the placeholder already used in
`.env.example`. Covered by `tests/Feature/BootstrapSeedingTest.php`.

## Maintenance rule

Debt items should identify a concrete risk and exit condition. When resolved, remove the item and record the material outcome in `bugs-fixed.md` or an ADR.
