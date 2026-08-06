# Coding Standards

## General workflow

Use LIFT: Learn the relevant code and tests, Intend the smallest coherent change, Forge it in the existing structure, and Tune with focused plus full validation.

Preserve unrelated work. Do not commit credentials, generated build output, caches, logs, or local environment files.

## PHP and Laravel

- Target PHP 8.2+ and Laravel 12.
- Follow PSR-12 and existing Laravel conventions; run `vendor/bin/pint`.
- Use typed parameters and return types.
- Keep controllers focused on HTTP concerns; place orchestration and domain behavior in services/jobs.
- Validate input in Form Request classes.
- Enforce permissions at the route boundary and object visibility/ownership in models or controllers.
- Prefer Eloquent relationships and scopes over duplicated queries.
- Use configuration files for environment-backed settings; avoid new `env()` calls outside `config/`.
- Use named routes and explicit constraints for resource parameters.
- Never log secrets, raw credentials, full integration payloads, or unmasked business data.
- Keep external integrations behind connector/provider interfaces.
- All AI and connector capabilities must be allow-listed, read-only, bounded, validated, and audited unless a separately approved design changes that boundary.

The existing seeder reads `BI_ADMIN_*` directly. Treat that as a legacy exception; new runtime code should use configuration.

## Vue and CSS

- Use Vue 3 Composition API and PrimeVue components already established by `App.vue`.
- Keep all requests through the configured Axios client so cookies and CSRF behavior remain consistent.
- Provide loading, empty, error, success, and permission-denied states.
- Preserve responsive and keyboard-accessible behavior; label form controls.
- Prefer extracting cohesive components/composables from `App.vue` when touching a sufficiently isolated area; do not perform an unrelated wholesale rewrite.
- Lazy-load heavy features such as charting.
- Use the existing CSS tokens and component vocabulary before adding new styling primitives.

## Database

- Use additive Laravel migrations and explicit foreign-key actions.
- Encrypt sensitive business payloads with Laravel casts or an approved encryption service.
- Add database-specific migration branches when changing encrypted JSON/text storage and test them against PostgreSQL.
- Avoid unbounded result sets; apply explicit limits or pagination.
- Do not place credentials or secrets in seed fixtures.

## Tests

- Add or update tests with every behavior change.
- Use feature tests for HTTP authorization, validation, audit, and persistence behavior.
- Use unit tests for isolated calculations and services.
- Keep tests independent of the developer database. `tests/bootstrap.php` intentionally removes stale Laravel optimization caches, and PHPUnit forces in-memory SQLite.
- Use Laravel HTTP, mail, queue, and notification fakes; do not call real providers in the default suite.
- Run the focused test first, then `php artisan test`.
- Run `npm run build` for frontend changes and `composer validate --no-check-publish` for dependency/metadata changes.

## Documentation

- Update `ai/api-contracts.md` for route or payload changes.
- Update `ai/database-schema.md` for migrations.
- Add an ADR for a durable architectural decision.
- Record unresolved defects and debt in `ai/issues/`.
- Record meaningful resolved defects in `ai/issues/bugs-fixed.md`.
- Avoid claiming external acceptance, load, penetration, or production verification without evidence.
