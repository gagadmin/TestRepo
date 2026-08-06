# Ask GAHolding

Ask GAHolding is a centralized, AI-powered business intelligence and reporting platform built with Laravel 12, Vue 3, PrimeVue, ApexCharts, PostgreSQL/MySQL, and Google AI Studio, OpenAI, or Azure OpenAI.

It connects governed enterprise APIs—including CRM, ERP, SAP, asset, procurement, website analytics, and internal applications—and turns authorized data into natural-language answers, dashboards, reports, exports, schedules, and analytical insights. The current platform is read-only toward connected systems.

## Capabilities

- Session authentication, RBAC, active-account enforcement, and audit trails
- Encrypted integration credentials with HTTPS, redirect, unsafe-header, and SSRF controls
- Natural-language reporting through five allow-listed, read-only AI tools
- Encrypted conversations, citations, tool arguments, and execution summaries
- Executive and departmental dashboards with reusable report definitions
- Masked, encrypted report snapshots with retention management
- PDF and Excel exports with download audit trails
- Queue-driven daily, weekly, monthly, and custom scheduled reports
- Email attachments and Microsoft Teams adaptive-card delivery
- Delivery retries, channel-level idempotency, run history, and failure tracking
- Governed trends, robust anomaly detection, three-period forecasts, and recommendations

See [project context](ai/project-context.md), [architecture](ai/architecture.md), and the [feature overview](ai/features/feature-overview.md) for the full implementation map.

## Requirements

- PHP 8.2+ and Composer 2
- Node.js/npm compatible with Vite 6
- PostgreSQL for the intended shared environment, or an approved MySQL setup
- PHP extensions required by Laravel, the selected database, Dompdf, and PhpSpreadsheet
- Google AI Studio, OpenAI, or Azure OpenAI credentials for AI features
- Queue worker and scheduler for automated delivery

## Local setup

```bash
composer install
npm install
```

Copy `.env.example` to `.env`, then configure the database and optional provider/delivery settings:

```bash
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

For active development, `composer dev` starts Laravel, the queue listener, log tailing, and Vite together.

Do not use shared or production credentials locally. Automated tests always use isolated in-memory SQLite, even when Laravel optimization caches exist.

## Configuration

Important environment groups are:

- Database, cache, session, and queue (`DB_*`, `CACHE_STORE`, `SESSION_*`, `QUEUE_CONNECTION`)
- Initial administrator (`BI_ADMIN_*`)
- Integration network policy (`INTEGRATION_REQUIRE_HTTPS`, `INTEGRATION_ALLOW_PRIVATE_NETWORKS`)
- AI provider/model (`AI_*`, `OPENAI_*`, `AZURE_OPENAI_*`)
- Reporting limits and retention (`REPORT_*`)
- Email and Teams delivery (`MAIL_*`, `TEAMS_WEBHOOK_URL`)

Review `.env.example` and [deployment guidance](ai/deployment.md). Never commit `.env` or provider credentials.

## Required background processes

Scheduled delivery needs both Laravel's scheduler and a queue worker:

```bash
php artisan schedule:work
php artisan queue:work --tries=3 --timeout=180
```

In production, supervise queue workers and invoke `php artisan schedule:run` every minute through the operating system scheduler.

Email uses Laravel's configured mailer. Teams delivery requires a secure workflow webhook:

```dotenv
MAIL_MAILER=smtp
TEAMS_WEBHOOK_URL=https://...
```

Teams deliveries contain an adaptive-card summary and authenticated report link. Email deliveries attach the generated PDF or Excel workbook.

## Development workflow

Read [AGENTS.md](AGENTS.md) and use LIFT: Learn, Intend, Forge, Tune. Key validation commands:

```bash
vendor/bin/pint --test
composer validate --no-check-publish
php artisan test
npm run build
php artisan route:list
php artisan schedule:list
php artisan migrate:status
```

The verified onboarding baseline is 44 passing tests with 167 assertions plus a successful production frontend build.

## Production checklist

- Use HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, encrypted sessions, and secure cookies.
- Use a secret manager for `APP_KEY`, database credentials, AI keys, integration secrets, SMTP, and Teams.
- Rehearse migrations and restoration on the approved PostgreSQL version.
- Configure and monitor queue workers, the scheduler, failed jobs, and report delivery.
- Configure centralized logging, backup/restore, readiness monitoring, and egress controls.
- Run tests and build verification before the release, then `php artisan optimize`.
- Complete live source, AI-grounding, SMTP, Teams, security, recovery, load, and business acceptance.

## Required CI secrets

The GitHub Postman synchronization workflow requires:

- `POSTMAN_API_KEY`
- `POSTMAN_COLLECTION_ID`
- `PROD_API_BASE_URL`
- `STAGING_API_BASE_URL`

Use GitHub environments and a non-production Postman collection while validating the workflow. Route extraction is best-effort because Laravel group prefixes and multiline declarations require manual review.

## Documentation index

- [Project context](ai/project-context.md)
- [Architecture](ai/architecture.md)
- [Database schema](ai/database-schema.md)
- [API contracts](ai/api-contracts.md)
- [Coding standards](ai/coding-standards.md)
- [Deployment and operations](ai/deployment.md)
- [Known issues](ai/issues/known-issues.md)
- [Troubleshooting](ai/issues/troubleshooting.md)
