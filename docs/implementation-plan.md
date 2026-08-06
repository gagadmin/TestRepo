# Ask GAHolding — Implementation Plan

## Delivery principles

- Deliver one production-shaped vertical slice per phase.
- Keep all data access behind Laravel authorization and approved service contracts.
- Treat AI output as derived content; preserve source, calculation, tool, and audit metadata.
- Use PostgreSQL in shared environments and SQLite only for local automated tests.
- Require automated tests, security review, and acceptance sign-off before closing a phase.

## Phase 1 — Foundation and access

Status: Implemented

Scope:

- Laravel 12 application foundation and Vue 3/PrimeVue interface
- Session authentication with login throttling and session regeneration
- Role-based access control for Administrator, Executive, Manager, and Analyst
- Core platform schema for integrations, conversations, messages, reports, schedules, and audits
- Audit recording for authenticated mutating requests
- Responsive executive overview and delivery status
- PostgreSQL-ready environment template and SQLite test environment

Acceptance criteria:

- Active users can sign in and inactive users are rejected.
- Authenticated users can load the platform bootstrap payload.
- Permission-protected routes reject users without the required permission.
- Default roles and permissions seed idempotently.
- Core migrations run from an empty database.
- PHP tests and the frontend production build pass.

## Phase 2 — Enterprise integrations

Status: Implemented

Scope:

- Connector contract, registry, and health service
- Encrypted API credential storage
- CRM, ERP, SAP, procurement, asset, and website analytics connector definitions
- Connection testing, retries, timeouts, rate-limit handling, and sync logs
- Administration interface and system integration matrix

Delivered:

- Permission-protected source registry with create, edit, test, and remove operations
- Encrypted bearer tokens, API keys, basic-auth credentials, and custom headers
- Configurable timeouts, retries, health endpoints, and primary data endpoints
- Persistent connection-test history with status, timing, HTTP status, and safe error context
- HTTPS enforcement and private/reserved network protection with an explicit enterprise-network opt-in
- Responsive PrimeVue administrator workspace with health summaries and test results

Exit gate:

- The generic HTTP connector and health-test workflow pass end-to-end automated tests.
- Two real source systems must complete acceptance testing when their endpoints and credentials are supplied.
- Credentials never appear in API output, application logs, or audit metadata.

## Phase 3 — AI reporting engine

Status: Implemented

Scope:

- Google AI Studio, OpenAI, and Azure OpenAI provider abstraction
- Approved tool registry with schema validation and authorization
- Conversation history, citations, calculations, and tool-call audit records
- Initial tools for sales, assets, procurement, website analytics, and CRM pipeline
- Prompt-injection controls and repeatable AI evaluation suite

Delivered:

- Provider abstraction for Gemini generateContent and OpenAI/Azure Responses APIs
- Configurable GPT-5.6 Sol model, reasoning effort, output limits, history depth, and tool-round limits
- Strict JSON-schema definitions for five approved read-only reporting tools
- Application-managed tool loop with server-side argument validation and authorization
- Local conversation and message history with provider, model, token, latency, citation, and tool-call metadata
- Audited tool-execution records without storing raw source payloads
- Prompt-injection boundary that treats all source output as untrusted data
- User-facing Ask GAHolding workspace with conversation history, source badges, tool activity, and provider readiness
- Provider calls use server-side credentials, bounded retries, and sequential allow-listed tool execution

Exit gate:

- Tool selection, parameter validation, authorization, and grounded-response thresholds pass.
- The model cannot execute arbitrary database queries or call unapproved endpoints.

## Phase 4 — Dashboards and exports

Scope:

- Executive and departmental dashboards
- Filters, saved views, drilldowns, reusable report definitions, and ApexCharts
- PDF and Excel generation with row-level access enforcement

Exit gate:

- Business owners approve KPI calculations and export accuracy.
- Standard reports meet the agreed normal-load response target.

## Phase 5 — Scheduled delivery

Scope:

- Timezone-aware daily, weekly, monthly, and custom schedules
- Queue-based generation, retries, delivery history, and failure alerts
- Email and Microsoft Teams delivery

Exit gate:

- Scheduled reports deliver inside the agreed service window.
- Failures are visible, retryable, and fully audited.

## Phase 6 — Assurance, rollout, and advanced analytics

Scope:

- Load, penetration, recovery, and user acceptance testing
- Pilot deployment, training, operational runbooks, monitoring, and support handover
- Later releases: anomaly detection, prediction, recommendations, and workflows

Exit gate:

- Production readiness, security, recovery, and business acceptance are formally approved.
