# Deployment and Operations

## Current state

The repository does not contain application-owned Docker, Kubernetes, web-server, process-supervisor, or infrastructure-as-code definitions. A deployment target and operational platform must therefore be selected and documented before production release. Vendor Dockerfiles under `vendor/laravel/sail` are dependencies, not this application's deployment definition.

The current directory was not a Git working tree during onboarding. GitHub Actions will not run until the project is initialized/imported into a GitHub repository.

## Runtime requirements

- PHP 8.2+ with extensions required by Laravel, Dompdf, database driver, and PhpSpreadsheet
- Composer 2
- Node.js/npm compatible with Vite 6
- PostgreSQL for the intended shared environment, or an explicitly approved MySQL deployment
- A web server/PHP runtime
- Long-running Laravel queue workers
- A scheduler invoking `php artisan schedule:run` every minute
- SMTP or another Laravel mail transport when email delivery is enabled
- Microsoft Teams workflow webhook when Teams delivery is enabled
- Google AI Studio, OpenAI, or Azure OpenAI credentials for AI chat
- Egress to approved AI and enterprise API endpoints

## Build and release

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
```

Run tests and build verification before the production install:

```bash
composer validate --no-check-publish
php artisan test
npm run build
php artisan route:list
php artisan schedule:list
```

Use atomic releases where possible. Back up the database and application encryption keys before migrations. Encrypted-column migrations may rewrite entire tables and need production-sized rehearsal.

## Required processes

Queue worker:

```bash
php artisan queue:work --tries=3 --timeout=180
```

Scheduler:

```bash
php artisan schedule:run
```

Supervise workers, restart them after a deployment, and use a single-server scheduler lock backed by a shared cache when multiple application nodes are running.

## Environment groups

Never commit environment values. Configure at least:

- Application: `APP_ENV`, `APP_KEY`, `APP_URL`, debug/locale/session security
- Database, cache, session, and queue connections
- Seed bootstrap identity (`BI_ADMIN_*`) only for controlled initialization
- Integration network policy (`INTEGRATION_REQUIRE_HTTPS`, `INTEGRATION_ALLOW_PRIVATE_NETWORKS`)
- Google Search Console property and service-account path (`GOOGLE_SEARCH_CONSOLE_SITE_URL`, `GOOGLE_APPLICATION_CREDENTIALS`) when Search Console reporting is enabled
- AI provider/model and the matching Google AI Studio, OpenAI, or Azure credentials
- Optional AI transient-failure policy (`AI_PROVIDER_RETRY_ATTEMPTS`, `AI_PROVIDER_RETRY_BASE_DELAY_MS`)
- Reporting row, retention, analytics, and anomaly limits
- SMTP/mail sender and optional Teams webhook

Use HTTPS, `APP_DEBUG=false`, encrypted sessions, secure cookies, and least-privilege database/API accounts in production. Retain old encryption keys in `APP_PREVIOUS_KEYS` during a controlled key rotation.

## Database and recovery

- Test all migrations against the chosen PostgreSQL version.
- Define backup frequency, retention, encryption, off-site storage, and restore objectives.
- Restore-test both the database and the corresponding `APP_KEY`/previous keys.
- Monitor failed jobs and report delivery failure counts.
- Purge snapshots through the registered daily command according to retention policy.

## Health and observability

- Use `/up` for basic application availability.
- Monitor HTTP error rates and latency, queue depth/age, failed jobs, scheduler freshness, integration health, AI provider failures, delivery failures, and database capacity.
- Centralize logs with sensitive-field redaction.
- Add an authenticated operational readiness check before production; `/up` alone does not prove database, queue, mail, AI, or enterprise API readiness.

## Rollback

Prefer rolling back the application release while preserving forward-compatible database changes. Some down migrations decrypt and convert business payload columns and can be expensive or risky on large datasets; do not rely on them as the primary production rollback mechanism.

## GitHub CI secrets

The Postman synchronization workflow requires repository or environment secrets:

- `POSTMAN_API_KEY`
- `POSTMAN_COLLECTION_ID`
- `PROD_API_BASE_URL`
- `STAGING_API_BASE_URL`

Protect production secrets with GitHub environments and required reviewers. The workflow updates an external Postman collection, so test it against a non-production collection first.
