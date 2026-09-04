# Known Issues and Risks

## Open issues

| ID | Severity | Issue | Impact / mitigation |
| --- | --- | --- | --- |
| KI-001 | High | Production integrations have not been accepted against real vendor APIs. | Generic endpoints, pagination, authentication variants, and schemas may differ. Complete a source-by-source contract test before release. |
| KI-002 | High | PostgreSQL production migration rehearsal is not evidenced by the local SQLite test suite. | Encrypted JSON-to-text migrations contain PostgreSQL-specific SQL. Rehearse with production-sized anonymized data and a backup/restore test. |
| KI-003 | High | No application-owned deployment or process-supervision definition exists. | Queue and scheduler outages can silently stop scheduled reports. Select a deployment platform and add supervised workers, monitoring, and runbooks. |
| KI-004 | Medium | The Postman route-sync workflow is necessarily heuristic. | Laravel group prefixes, variables, and multiline route declarations cannot be reconstructed reliably from added diff lines. Review every generated collection change manually. |
| KI-005 | Medium | APIs are unversioned, session-authenticated, and defined in `routes/web.php`. | External consumers have no stable versioned contract; keep APIs same-origin until an explicit public API design is approved. |
| KI-006 | Medium | `resources/js/pages/LegacyWorkspacePage.vue` contains roughly 3,600 lines and owns the UI state of the six unmigrated views. | Corrected 2026-09-04: this issue previously named `App.vue`, which is now 44 lines and holds only the layout switch. The monolith moved rather than shrank. Changes still carry broad regression scope; extract the remaining views incrementally per TD-001, checking for orphaned script after each. |
| KI-007 | Medium | Frontend coverage is Vitest unit/component tests only; there is no end-to-end suite. | A Vitest suite now covers routing, the identity gate, navigation permission filtering, stores, services, formatting composables, the AI tools admin logic, the security dashboard logic, and the SEO insights logic. Browser interaction, accessibility, and rendering regressions across a full journey remain uncovered; add Playwright smoke paths. |
| KI-008 | Medium | Live AI, email, Teams, and enterprise API behavior is not exercised by the default automated suite. | Use controlled staging acceptance and synthetic monitoring; never use production secrets in the test suite. |
| KI-009 | Medium | `/up` only proves basic Laravel availability. | It does not establish database, queue, scheduler, mail, AI, or source readiness. Add protected dependency/readiness diagnostics. |
| KI-010 | Medium | Several list APIs lack explicit pagination. | Data volume can degrade latency and memory use. Add stable pagination and consumer-compatible response envelopes. |
| KI-011 | Medium | No OpenAPI schema or consumer contract tests exist. | Route and payload drift is possible. Adopt OpenAPI and validate representative responses in CI. |
| KI-013 | Medium | Freshservice scheduled-email rollout is paused pending live acceptance. | Implementation, migration, PDF/Excel generation, and automated tests are complete. Before activation, confirm recipients and cadence, run a controlled SMTP delivery, compare aggregate counts with Freshservice, and verify the queue worker and scheduler are supervised. |
| KI-015 | Medium | The user access profile migration has not been rehearsed against production-sized data. | `2026_09_03_000100_add_user_access_profile_columns` backfills `allowed_departments` from `department` and is behaviour-preserving in tests only. Rehearse on PostgreSQL and compare per-user dashboard visibility before and after, per `ai/change-requests/WEB-671/role-based-navigation-and-configurable-dashboard-access.md`. |
| KI-016 | Medium | Scheduled report recipients have not been re-checked against the user access profile. | A schedule runs with its creator visibility, so a recipient could receive content their own profile no longer permits them to open interactively. Review active schedules and escalate any mismatch to the business owner rather than changing entitlements silently. |
| KI-017 | Low | `ReportDataService` passes `department`, `region`, and `status` filters to the reporting gateway, but the generic HTTP connector forwards only `report_type` and the date/limit bounds. | Reports are still filtered correctly, because the filters are applied to the retrieved rows; the cost is retrieving more rows than needed. Forwarding them would change outbound requests to enterprise APIs, so it needs a source-by-source contract decision rather than a silent change. `DashboardReportingTest` now asserts the actual forwarded query. |
| KI-018 | High | `.github/workflows/deployment.yml` deploys to a live environment with every safeguard commented out. | On a push to `staging` the workflow runs `git reset --hard origin/staging` and then `php artisan migrate --force` against a running application. `php artisan down`, the `supervisorctl restart all`, and `php artisan up` are commented out, so the schema change lands against live traffic; there is no backup step, contrary to the WEB-671 rollout plan, which requires an agreed low-usage window and a pre-deployment backup. `composer install`, `npm ci`, and `npm run build` are also commented out while `/vendor`, `/node_modules`, and `/public/build` are gitignored and untracked, so the deployed tree can serve stale dependencies and stale or absent browser assets alongside a migrated database. `appleboy/ssh-action@master` is pinned to a moving branch rather than a release tag or commit SHA, and no style, test, or build gate runs before the deploy step - this file is the repository's only workflow. Accepted as an open risk by decision on 2026-09-04; the workflow is unchanged. Do not merge to `staging` until it is corrected under its own approved Change Request, and treat any run of it as an unrehearsed production-style migration. |
| KI-019 | High | The deployment workflow targets a host that is not a sanctioned GA Holding environment. | The workflow ships application code, holds `VPS_HOST` / `VPS_USER` / `VPS_SSH_KEY` / `VPS_PORT` repository secrets, and runs migrations against `moikzzte@.../assets.moikzz.tech`, confirmed on 2026-09-04 as a personal or test host rather than an approved staging environment. Application code, schema changes, and any data on that host therefore sit outside sanctioned infrastructure and its backup, access, and retention controls. Accepted as an open risk by decision; no change was made. Before the workflow is corrected, confirm the approved target environment with the platform owner, rotate the stored deployment secrets, and confirm whether any application data already reached this host. |
| KI-020 | Low | `SECURITY_ALERT_THROTTLE_MINUTES` is documented and read into configuration, but nothing consults it. | `config/security.php` exposes `alerts.throttle_minutes` and `SECURITY.md` lists it among the alerting controls, so an operator setting it would reasonably believe alert volume is capped. No code reads the value. The practical exposure is limited: findings are deduplicated by fingerprint and each carries an `alerted` flag, so a persistent condition is announced once rather than on every five-minute scan, and a burst of alerts would require many genuinely distinct new findings. Decide whether to implement the throttle or remove the setting; until then `SECURITY.md` records that it has no effect. |

## Closed

| ID | Closed | Resolution |
| --- | --- | --- |
| KI-012 | 2026-09-04 | Vue Router is in use. `resources/js/router/` provides the route table, the identity gate, and deep-link handling; `app.js` mounts only after `router.isReady()`. Covered by `routes.spec.js` and `identityGate.spec.js`. The issue described the pre-router implementation. |
| KI-014 | 2026-09-04 | `public/storage` is linked to `storage/app/public` in the working environment; verified directly. |

## Pending todo

- [ ] Resume the Freshservice ITSM scheduled-email rollout.
- [ ] Confirm the approved recipient list, delivery frequency, time, timezone, and attachment format.
- [ ] Send one controlled test email from the configured SMTP environment.
- [ ] Compare the delivered summary with the corresponding live Freshservice analytics.
- [ ] Confirm the queue worker and Laravel scheduler remain continuously supervised before enabling the schedule.

## Security residual risks

- DNS resolution and connection occur at different times, so the integration URL guard reduces but cannot completely eliminate DNS rebinding/TOCTOU risk. Production egress controls should enforce allowed destinations.
- Encrypted application data remains recoverable by any actor with application runtime and `APP_KEY` access. Use a secret manager, restricted runtime identities, encrypted backups, and audited key access.
- Development CSP permits inline/eval scripts and broad HTTP/WebSocket connections to support local tooling. Never run a production environment with `APP_ENV=local` or `testing`.
- Report and AI data can contain sensitive business information even after masking rules. Validate masking against real classifications and roles.

## Not defects

- Analytics forecasting is intentionally a transparent three-period deterministic projection, not a trained predictive model.
- Private-network integration URLs are intentionally rejected unless an administrator enables the controlled enterprise-network option.
- The MVP intentionally exposes no transactional connectors, arbitrary SQL, arbitrary HTTP, shell, or code-execution tools.
