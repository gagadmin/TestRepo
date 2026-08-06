# Technical Debt

## Priority backlog

### TD-001 — Decompose the Vue application

`resources/js/App.vue` owns navigation, data loading, forms, dashboards, AI chat, reports, schedules, and analytics. Extract feature-oriented components and composables while preserving the current request layer and visual design. Add component tests before moving high-risk state.

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

### TD-008 — Centralize audit policy

Current audit coverage combines middleware and feature-specific records. Define an auditable-event catalog, retention/export policy, immutable storage expectations, and an authorized audit UI.

### TD-009 — Replace seeder `env()` access

`DatabaseSeeder` reads `BI_ADMIN_*` directly. Move these settings to a configuration file or an explicit secure bootstrap command so configuration caching and runtime conventions remain consistent.

### TD-010 — Add systematic dependency/security automation

Run Composer and npm vulnerability audits, license review, secret scanning, static analysis, and dependency update checks in CI. Network-restricted local execution should not be the only control.

## Maintenance rule

Debt items should identify a concrete risk and exit condition. When resolved, remove the item and record the material outcome in `bugs-fixed.md` or an ADR.
