# Bugs Fixed

This log records material defects resolved before or during repository onboarding. It is not a substitute for commit history.

## Authentication and authorization

- Deactivated accounts now lose existing sessions through active-user middleware instead of only being blocked at the next login.
- Login email input is normalized and length-bounded.
- Successful login, failed login, logout, and deactivated-session events are audited without storing raw attempted email addresses; fingerprints are used where appropriate.
- AI conversation show/delete operations enforce ownership.
- Report-schedule operations apply authorization checks consistently.
- Administrators can now open Users & Access and Audit Trail from the SPA. User changes support roles, department, title, and activation status while preventing self-lockout and removal of the final active administrator.
- Department assignment is visible in Users & Access and accepts existing suggestions or a normalized new value; access changes produce explicit safe before/after audit evidence.
- Newly provisioned users no longer enter an infinite frontend redirect between forced password change and mandatory MFA enrollment. Identity gates now run sequentially: password change first, followed by MFA setup.

## Integration security

- HTTP redirects are disabled for governed integration requests.
- Integration base URLs are validated against HTTPS policy and private/reserved network policy.
- Unsafe transport headers, embedded credentials, and header line breaks are rejected both during validation and at runtime.
- A data source cannot be deleted while a report definition still references it.
- Credentials and configured headers remain encrypted and are never returned through API resources.
- Google Search Console now uses a dedicated Google API connector rather than nonexistent `/health` or reporting endpoints on the company website; live Search Analytics data is available to the dashboard and report layer.
- Editing a Google Search Console source no longer injects the hidden generic `/health` default; legacy health/data paths are stripped from Search Console create and update payloads.
- Bare Search Console URL-prefix properties are normalized with a trailing slash so values such as `https://gwm.sy` match Google's canonical `https://gwm.sy/` identifier.

## AI governance and resilience

- Conversation titles, messages, citations, tool-call metadata, tool arguments, and safe summaries are encrypted at rest.
- Provider connection failures and malformed JSON now produce controlled application errors.
- Unsupported AI provider configuration is handled explicitly.
- Tool execution remains limited to validated, authorized, read-only allow-listed functions.

## Reporting, scheduling, and frontend reliability

- Report snapshots, schedule recipients, and analytics payloads are encrypted at rest.
- CSRF/XSRF behavior is configured for same-origin Axios requests.
- Report links can restore the selected report through a query parameter.
- Navigation state, schedule permissions, and a password-label defect were corrected.
- The overview greeting and date now follow the browser's local time instead of displaying fixed morning/date text.
- The Google Search Console explorer is now scoped to dashboards containing a website-analytics report instead of appearing and loading on every departmental dashboard.
- Freshservice is now presented through a dedicated ITSM dashboard with live aggregate ticket, assignment, priority, status, due-date, and SLA breach analytics instead of a generic empty dashboard.
- Freshservice custom statuses are retrieved from the correct ticket-form-fields endpoint. SLA-paused status IDs are configurable per source, preventing on-hold tickets from inflating overdue and due-today metrics.
- The Vite production build removes a stale Laravel `public/hot` marker so production asset resolution does not target a stopped development server.
- PrimeIcons now load in local Vite development: the CSP permits the configured Vite origin for `font-src`, while production remains restricted to same-origin and embedded fonts. PrimeIcons is an installed open-source dependency and requires no paid subscription.

## Test isolation

- PHPUnit forces in-memory SQLite and removes generated config, route, and event caches before bootstrapping. This prevents stale local optimization artifacts from redirecting tests to a developer database or stale routes.

## Code quality

- The repository-wide Laravel Pint baseline is clean after applying mechanical formatting corrections to the remaining legacy files.

## Verification baseline

The 2026-07-29 LIFT audit passed with 64 tests and 291 assertions. Laravel Pint, the Vite production build, Composer validation, route loading, schedule registration, and migration status checks passed. A controlled live Freshservice read also returned aggregate status, priority, agent, group, due-date, and SLA data without exposing ticket bodies or credentials.
# Profile link restored for legacy workspace users (2026-07-31)

- **Symptom:** Signed-in users on the overview and other legacy workspace pages had no visible route to their Profile page to change their password.
- **Cause:** The extracted application sidebar linked its identity block to `profile`, but the still-active legacy workspace sidebar rendered the same block as plain text.
- **Fix:** Both shells now share a discoverable `UserAccountLink` labelled **Profile & security**, available to every authenticated user without requiring an administrative permission.
- **Regression coverage:** A component test verifies the visible label, accessible user-specific label, and navigation to the `profile` route.
