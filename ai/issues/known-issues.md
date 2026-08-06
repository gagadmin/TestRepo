# Known Issues and Risks

## Open issues

| ID | Severity | Issue | Impact / mitigation |
| --- | --- | --- | --- |
| KI-001 | High | Production integrations have not been accepted against real vendor APIs. | Generic endpoints, pagination, authentication variants, and schemas may differ. Complete a source-by-source contract test before release. |
| KI-002 | High | PostgreSQL production migration rehearsal is not evidenced by the local SQLite test suite. | Encrypted JSON-to-text migrations contain PostgreSQL-specific SQL. Rehearse with production-sized anonymized data and a backup/restore test. |
| KI-003 | High | No application-owned deployment or process-supervision definition exists. | Queue and scheduler outages can silently stop scheduled reports. Select a deployment platform and add supervised workers, monitoring, and runbooks. |
| KI-004 | Medium | The Postman route-sync workflow is necessarily heuristic. | Laravel group prefixes, variables, and multiline route declarations cannot be reconstructed reliably from added diff lines. Review every generated collection change manually. |
| KI-005 | Medium | APIs are unversioned, session-authenticated, and defined in `routes/web.php`. | External consumers have no stable versioned contract; keep APIs same-origin until an explicit public API design is approved. |
| KI-006 | Medium | `resources/js/App.vue` contains more than 2,000 lines and owns most UI state. | Changes have broad regression scope. Extract feature components and composables incrementally with frontend tests. |
| KI-007 | Medium | There is no frontend unit or end-to-end test suite. | PHP tests cannot detect browser interaction, accessibility, or rendering regressions. Add Vitest/component tests and Playwright smoke paths. |
| KI-008 | Medium | Live AI, email, Teams, and enterprise API behavior is not exercised by the default automated suite. | Use controlled staging acceptance and synthetic monitoring; never use production secrets in the test suite. |
| KI-009 | Medium | `/up` only proves basic Laravel availability. | It does not establish database, queue, scheduler, mail, AI, or source readiness. Add protected dependency/readiness diagnostics. |
| KI-010 | Medium | Several list APIs lack explicit pagination. | Data volume can degrade latency and memory use. Add stable pagination and consumer-compatible response envelopes. |
| KI-011 | Medium | No OpenAPI schema or consumer contract tests exist. | Route and payload drift is possible. Adopt OpenAPI and validate representative responses in CI. |
| KI-012 | Low | UI navigation uses local state rather than Vue Router. | Browser history and deep linking are limited; only report query links receive special handling. |
| KI-013 | Medium | Freshservice scheduled-email rollout is paused pending live acceptance. | Implementation, migration, PDF/Excel generation, and automated tests are complete. Before activation, confirm recipients and cadence, run a controlled SMTP delivery, compare aggregate counts with Freshservice, and verify the queue worker and scheduler are supervised. |
| KI-014 | Low | `public/storage` was not linked in the inspected local environment. | Link it if future features expose public-disk assets; current report downloads do not rely on it. |

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
