# Ask GAHolding — Project Context

## Purpose

Ask GAHolding is a centralized, AI-assisted business intelligence and reporting platform. It connects approved enterprise APIs, lets authorized users ask business questions in natural language, and produces governed dashboards, reports, exports, schedules, and analytical insights.

The MVP is read-only with respect to connected enterprise systems. It retrieves and analyzes data but does not execute approvals, update source records, or automate business workflows.

## Business outcomes

- Reduce manual data extraction and report preparation.
- Give executives and managers a consolidated view of enterprise performance.
- Enable governed self-service analysis without exposing arbitrary database or HTTP access.
- Preserve source, calculation, delivery, and audit evidence.
- Establish a platform for later anomaly detection, prediction, recommendations, and workflow automation.

## Stakeholders and users

| Group | Primary needs |
| --- | --- |
| Executive management | Enterprise KPIs, concise summaries, trend visibility |
| Finance | Financial views, scheduled reports, exports |
| Sales and marketing | Pipeline, sales, and website analytics |
| Procurement and operations | Spend, supplier, operational, and asset reporting |
| Analysts | Reusable reports, AI chat, analytics, exports, schedules |
| IT and administrators | Integrations, access control, security, auditability, operations |

The seeded RBAC model defines Administrator, Executive, Manager, and Analyst roles. Permissions, rather than role names alone, protect server routes.

## Current implementation

The repository contains a Laravel 12 modular monolith with a Vue 3 single-page interface.

- Session authentication, account activation controls, RBAC, and audit logs
- Governed CRM, ERP, SAP, asset, procurement, website, and internal HTTP data sources
- Dedicated read-only Google Search Console and Freshservice analytics connectors
- Encrypted integration credentials and guarded outbound requests
- Google AI Studio/Gemini, OpenAI, or Azure OpenAI reporting assistant with five allow-listed read-only tools
- Encrypted conversation history, citations, tool calls, and execution evidence
- Executive and departmental dashboards backed by reusable report definitions
- Report generation, encrypted snapshots, PDF export, and Excel export
- Queue-driven daily, weekly, monthly, and custom schedules
- Email attachment and Microsoft Teams adaptive-card delivery
- Deterministic trends, robust anomaly detection, short-horizon forecasting, and recommendations

## Scope boundaries

In scope:

- API-based source configuration and health testing
- Natural-language analytics and approved AI tool calling
- Dashboards, reusable reports, snapshots, and citations
- PDF and Excel exports
- Scheduled email and Teams delivery
- Governed analytical insight generation

Out of scope for the current implementation:

- Writes to connected business systems
- Autonomous approvals or transactional actions
- General-purpose SQL, shell, code-execution, or arbitrary HTTP tools
- Full workflow orchestration
- Vendor-specific normalization beyond the implemented Search Console/Freshservice connectors and governed generic HTTP connector
- A trained predictive machine-learning pipeline

## Source of truth

For behavior, prefer sources in this order:

1. Automated tests and executable routes
2. Controllers, services, jobs, models, migrations, and configuration
3. Files in `ai/` and `docs/`
4. The original BRD and planning narrative

When documentation and code disagree, record the discrepancy in `ai/issues/known-issues.md` and update the documentation in the same change that resolves it.

## Verified baseline

At onboarding, the application used PHP 8.3.30 and Laravel 12.64.0. The full automated suite passed with 44 tests and 167 assertions, the Vite production build passed, Composer metadata validated, routes loaded, and scheduled commands were registered. Tests use in-memory SQLite; the local runtime was configured for MySQL, while shared/production environments are intended to use PostgreSQL or another approved Laravel-supported database.
