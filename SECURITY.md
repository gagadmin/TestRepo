# Security Overview — Ask GAHolding

**Application:** Ask GAHolding — AI-powered business intelligence and reporting platform
**Stack:** Laravel 12, Vue 3 SPA, PostgreSQL/MySQL, session authentication
**Posture:** Read-only toward connected source systems; records and alerts on security events but never takes automated containment action
**Document status:** Reference documentation generated from source. Where documentation and code disagree, treat routes, middleware, services, and tests as authoritative (per `AGENTS.md`).

---

## 1. Purpose and scope

This document describes the security architecture of Ask GAHolding: how users authenticate, how requests are authorized, how sensitive data is protected at rest and in transit, how outbound integration traffic is constrained, and how the built-in security monitoring subsystem detects and reports suspicious activity.

The platform is deliberately **read-only** toward the enterprise systems it connects to (CRM, ERP, SAP, asset, procurement, analytics). It never writes back to source systems, never executes arbitrary SQL or shell commands, and never takes autonomous containment action against accounts. Every response to a security finding is a human decision.

Two guiding principles run through the codebase:

- **Deny by default.** New routes inherit authentication, active-account, MFA, and current-password gates because those middleware are applied at the route-group level rather than per route. A developer must actively opt a route out of protection, not opt it in.
- **Record everything sensitive, expose nothing sensitive.** Authentication events, mutating requests, and administrative actions are audited, but plaintext emails, passwords, TOTP secrets, and recovery codes are never written to logs or the audit trail.

---

## 2. Authentication

### 2.1 Two-step sign-in with a strict pre-session invariant

Authentication is a deliberate two-step flow implemented in `AuthController`:

1. `POST /auth/login` — verifies the password only. For an account enrolled in two-factor authentication, **a valid password alone establishes no authenticated session.** The controller uses `Auth::validate()` rather than `Auth::attempt()`, so the credential is checked without logging anyone in. The server holds only a short-lived, unprivileged "pending" record in the session.
2. `POST /auth/two-factor` — verifies the TOTP or recovery code and only then calls `Auth::login()` and establishes the privileged session.

This invariant is protected by an explicit regression test (`test_no_authenticated_session_exists_before_the_second_factor`). The pending record carries an expiry (`security.two_factor.challenge_ttl_minutes`, default 5 minutes); an expired challenge returns HTTP 410 and forces a restart from the password step.

### 2.2 User enumeration resistance

A wrong password, an unknown email address, and a deactivated account all return the **identical** validation message ("The supplied credentials are not valid."). An attacker cannot distinguish "no such user" from "wrong password" from "account disabled."

### 2.3 Session establishment and fixation defence

On successful authentication, `establishSession()` calls `Auth::login()`, then `session()->regenerate()` to issue a fresh session ID for the newly privileged session (session-fixation defence), records `last_login_at`, and writes an `auth.login` audit event. Logout invalidates the session and regenerates the CSRF token. A self-service password change also regenerates the session, so a change prompted by suspected compromise kills any concurrent attacker session.

### 2.4 Password hashing

Passwords are stored using Laravel's `hashed` cast (bcrypt, `BCRYPT_ROUNDS=12` in the example environment). The plaintext password is never logged; the audit trail records only that a password changed.

---

## 3. Multi-factor authentication (TOTP)

Implemented in `TwoFactorService` using `pragmarx/google2fa`, satisfying the intent of ISO 27001 A.8.5 and NIST IA-2.

- **Standard:** TOTP per RFC 6238, 30-second step, verification window of ±1 step (`security.two_factor.window`) to tolerate clock drift while keeping the replay window small.
- **Enrolment:** A secret is generated and stored **encrypted** but flagged unconfirmed until the user proves possession by entering a valid code. The QR code is rendered server-side as inline SVG (`bacon/bacon-qr-code`), so the shared secret never leaves the application to an external chart service.
- **Replay protection:** The last-used time-step is recorded per user (`two_factor_last_used_timestep`). A code that is not strictly newer than the last accepted step is rejected, so a code observed over the shoulder or captured on a phishing page cannot be reused within its window.
- **Recovery codes:** Eight single-use codes (`security.two_factor.recovery_code_count`), stored **hashed** so a database reader cannot use them. Each is consumed on use, bounding the value of a leaked backup sheet.
- **Mandatory enrolment:** When policy requires a second factor (`MFA_REQUIRED_FOR_ALL`, or membership of `administrator` / `security_officer`), the `EnsureTwoFactorEnrolled` middleware **confines an unenrolled session to the enrolment endpoints only** — a user cannot sign in with a password and then ignore the enrolment prompt and keep working.
- **Sensitive changes require re-authentication:** Regenerating recovery codes or disabling MFA requires the current password (`confirmPassword()`), preventing a hijacked session from tearing down the second factor. A user cannot remove a second factor their role requires; only a `users.manage` administrator can.

---

## 4. Password policy (NIST SP 800-63B)

`PasswordPolicyService` implements a modern, breach-screening-focused policy rather than legacy composition rules.

- **Length:** minimum 12 characters (`PASSWORD_MIN_LENGTH`), maximum 4096. No composition rules (no forced mix of upper/lower/symbol) — 800-63B discourages them because they shrink the search space predictably.
- **No forced rotation by default.** `max_age_days` defaults to `0` (disabled), following 800-63B §5.1.1.2, which advises against periodic rotation because it drives weak incrementing passwords. The mechanism is fully implemented and can be enabled by configuration if an auditor requires a literal IA-5(1)(d) maximum-lifetime reading.
- **Breach / common-password screening** (`PASSWORD_BLOCK_COMPROMISED`): candidate passwords are checked case-insensitively against a bundled `resources/security/compromised-passwords.txt` list. A local list is used because arbitrary outbound HTTP is disallowed by the platform's architecture rules; the trade-off is coverage of common cases rather than every breached credential.
- **Contextual screening** (`PASSWORD_BLOCK_CONTEXTUAL`): rejects passwords containing the user's name, email local-part, or the application name.
- **Low-entropy screening:** rejects single-character repeats, strings with fewer than five distinct characters, and long keyboard/alphabet/number sequences that would otherwise pass a length check.
- **Reuse prevention:** the current password plus the last `PASSWORD_HISTORY_DEPTH` (default 5) hashes are checked; history is pruned to the configured depth.
- **Forced change on compromise:** administrators or the security agent can set `must_change_password`; `EnsurePasswordIsCurrent` then confines the session to the password-change endpoints until it is satisfied.
- **Temporary passwords** are generated as memorable multi-word passphrases (~44 bits of entropy) that themselves satisfy the policy, with a random-hex fallback.

The same rules are exposed as a validation rule (`CompliantPassword`) so form requests and the service enforce one policy, and the user sees every failure at once rather than one at a time.

---

## 5. Account lockout and rate limiting

### 5.1 Progressive lockout (CIS 6.2)

`LoginThrottleService` applies progressive backoff scoped to **(account, source address, stage)**:

- **Why the source address is part of the key:** a purely account-wide lock is a denial-of-service primitive — anyone who knows an email address could lock its owner out from anywhere. Keying on the source address means an attacker locks out only themselves, while a credential-stuffing run from one host is still shut down.
- **Backoff ladder:** after 5 failures (`LOCKOUT_THRESHOLD`), locks escalate through 1, 5, 15, 60, 240 minutes, with the final value repeating.
- **Separate budgets per stage:** password and second-factor attempts are counted separately, so a wrong TOTP code cannot exhaust the password budget.
- **Decay:** a quiet period (`LOCKOUT_DECAY_MINUTES`, default 60) resets the counter so an honest user who mistyped last week does not resume from a punished state.
- **Privacy:** the email is stored only as an HMAC-SHA256 fingerprint keyed on the app key — the plaintext address is never written to the throttle table or the audit log.
- **Response semantics:** a lockout returns HTTP **423 Locked** with a `retry_after_seconds` hint, distinct from a 429 rate limit.
- **Administrative unlock** clears locks across every source address (`AdminIdentityController`).

### 5.2 Per-route rate limits

Laravel throttles are applied at the route layer to constrain distributed attacks that a per-host lock would not catch: login `6/min`, two-factor challenge `10/min`, AI chat `20/min`, exports `30/min`, security scan `5/min`, and similar limits across mutating and expensive endpoints.

---

## 6. Authorization model

Authorization is layered so that a functional permission is never sufficient on its own for sensitive data.

- **Authentication + active account:** every route behind `['auth','active']`. `EnsureActiveUser` logs out and invalidates the session of any account deactivated mid-session (audited as `auth.session_revoked`).
- **MFA + current password:** the main application route group additionally requires `['mfa','password.current']`, applied to the whole group so newly added endpoints are protected by default.
- **Role/permission checks (RBAC):** `EnsurePermission` (`permission:<name>`) gates functional access — e.g. `users.manage`, `integrations.manage`, `reports.create`, `security.view`, `security.manage`, `ai.chat`, `analytics.run`. Permissions are resolved through role→permission relationships (`permission_role` table).
- **Organisational restriction on security data:** the security dashboard requires **both** the `security.view` permission **and**, via `EnsureSecurityAccess`, membership of the IT/security department or an `administrator`/`security_officer` role. Granting the permission alone to an unrelated role does not expose security telemetry, and denials are audited as `security.access_denied`.
- **Object-level ownership/visibility** is checked server-side in controllers (e.g. SEO properties are re-checked for the caller's visibility), consistent with the `AGENTS.md` invariant that resource access checks ownership/visibility beyond the functional permission.

### Route protection layers (summary)

| Layer | Middleware | Enforces |
|---|---|---|
| 1 | `auth` | Authenticated session exists |
| 2 | `active` | Account still active |
| 3 | `mfa` | Second factor enrolled (when required) |
| 4 | `password.current` | Password not flagged for change / not expired |
| 5 | `permission:<name>` | RBAC functional permission |
| 6 | `security.access` (security only) | IT/security department or privileged role |
| 7 | Controller | Object ownership / visibility |

---

## 7. Web request protections

### 7.1 Security headers (`AddSecurityHeaders`, appended to the web group)

Every response carries:

- **Content-Security-Policy:** `default-src 'self'`; `script-src 'self'` (production); `object-src 'none'`; `base-uri 'self'`; `form-action 'self'`; `frame-ancestors 'none'`. Development additionally allows the Vite dev-server origin and inline/eval scripts, gated on `local`/`testing` environments only.
- **Strict-Transport-Security:** `max-age=31536000; includeSubDomains` on secure connections.
- **X-Content-Type-Options:** `nosniff`
- **X-Frame-Options:** `DENY` (with `frame-ancestors 'none'` as the modern equivalent)
- **Referrer-Policy:** `no-referrer`
- **Permissions-Policy:** camera, microphone, geolocation all disabled
- **Cross-Origin-Opener-Policy:** `same-origin`
- **X-Powered-By** is removed.

### 7.2 CSRF and session cookies

The SPA uses Laravel's session guard with CSRF protection on the standard Axios path. Session cookies are hardened via environment configuration: `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`. Sessions are stored in the database (default driver) with a 120-minute idle lifetime, which also enables the active-session view on the security dashboard.

### 7.3 Audit trail (`AuditMutatingRequests`, appended to the web group)

Every non-GET request from an authenticated user (excluding login/logout, which have their own detailed audit events) is recorded with the actor, event name, path, response status, IP, and user agent. Combined with the explicit `auth.*`, `user.access.updated`, `report.exported`, and `analytics.generated` events, this provides the evidence base the security monitor reads from.

---

## 8. Data protection

### 8.1 Encryption at rest

Sensitive stored payloads use Laravel encrypted casts (AES-256-CBC, `APP_KEY`), including:

- User two-factor secrets (`encrypted`) and recovery-code sets (`encrypted:array`, additionally hashed)
- API/integration credentials (`ApiConfiguration`)
- Report snapshots (masked and encrypted)
- AI conversation content, citations, tool arguments, and execution summaries
- Scheduled-report recipient lists

**Key rotation** is supported via `APP_PREVIOUS_KEYS`, so rotating `APP_KEY` preserves the ability to decrypt existing data.

### 8.2 Serialization hygiene

The `User` model explicitly hides `password`, `remember_token`, `two_factor_secret`, and `two_factor_recovery_codes` from serialization, so a serialized user object can never leak the shared secret or recovery codes even when decrypted in memory.

### 8.3 Report snapshots and exports

Snapshots are masked and encrypted with retention management (`REPORT_SNAPSHOT_RETENTION_DAYS`), row limits (`REPORT_MAX_SNAPSHOT_ROWS`), and download audit trails on PDF/Excel exports. A scheduled command purges expired snapshots.

---

## 9. Integration / SSRF controls

Outbound calls to connected enterprise systems are constrained by `IntegrationUrlGuard.assertAllowed()` before any request is made:

- **Scheme allow-list:** only `http`/`https`; HTTPS can be forced (`INTEGRATION_REQUIRE_HTTPS=true`, default).
- **No embedded credentials, query strings, or fragments** in configured endpoint URLs.
- **SSRF / private-network guard:** unless `INTEGRATION_ALLOW_PRIVATE_NETWORKS=true` (default false), the guard rejects `localhost`/`*.localhost`, resolves the host via DNS (A/AAAA), and validates **every** resolved address against `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` — blocking access to private, loopback, link-local, and reserved ranges. Literal-IP hosts are validated directly.

Per the architecture invariants in `AGENTS.md`, external URLs also retain redirect, unsafe-header, timeout, retry, and response-size controls, and integrations stay behind the connector registry.

### AI tooling safety

The AI assistant operates behind an **allow-list** of read-only tools (`ToolRegistry`), executed sequentially, with tool arguments and execution summaries validated and stored encrypted. AI output is treated as untrusted until validated/masked. User-reported answer corrections stay **pending until an administrator approves them**, so reporting cannot steer future answers. Tool response size and tool-round counts are bounded (`AI_TOOL_RESPONSE_LIMIT_BYTES`, `AI_MAX_TOOL_ROUNDS`).

---

## 10. Security monitoring subsystem

A built-in monitoring agent continuously analyses the audit trail and configuration and raises `SecurityEvent` records. **It records and alerts only — it never disables accounts, revokes sessions, or blocks addresses.**

### 10.1 Detectors (`SecurityMonitor::scan()`)

The scan runs eleven detectors; a failure in one is logged and the scan continues (status `partial`). Findings carry a `category` (`threat`, `identity`, `data`, `governance`, `compliance`) and a `severity` (`critical`, `high`, `medium`, `low`, `info`).

| Detector | Reads | Flags (high level) | Typical severity |
|---|---|---|---|
| Brute force (by IP / by account) | `auth.login_failed` | Failures over threshold from one IP, or against one account | high → critical |
| Credential stuffing | `auth.login_failed` | Many distinct accounts probed from one IP | critical |
| Session anomaly | `auth.login` | One user signing in from many distinct IPs | medium → high |
| Privilege escalation | `user.access.updated` | Gaining `administrator`/`security_officer`, or reactivation | medium → high |
| Data exfiltration | `report.exported`, `analytics.generated` | Export volume over threshold per user/IP | medium → high |
| Dormant account activity | `auth.login` | Sign-in after ≥ `dormant_days` of inactivity | medium |
| After-hours admin activity | access/integration events | Admin actions outside business hours or on weekends | low |
| Inactive session probing | `auth.session_revoked` | Repeated activity on revoked/inactive sessions | medium |
| Configuration drift | runtime config | Debug on in prod, unencrypted/insecure sessions, HTTPS off, private networks allowed, over-long session lifetime | medium → critical |
| Credential exposure | data sources, users | Integrations over plain HTTP; privileged accounts never used | medium → high |
| Two-factor gaps | config, users | MFA disabled globally; privileged accounts unenrolled; near-exhausted recovery codes | low → critical |

**Detection thresholds and windows are fully configurable** in `config/security.php` (see the reference table in §12) so they can be tuned per environment without editing detection code.

### 10.2 Deduplication and lifecycle

Each finding has a stable `fingerprint`. On a repeat, `SecurityMonitor::record()` increments `occurrences` and updates `last_detected_at` rather than inserting a duplicate row; a finding previously marked `resolved`/`false_positive` is **reopened**. Event statuses are `open`, `acknowledged`, `resolved`, `false_positive`.

### 10.3 Posture scoring (`SecurityPostureService`)

The dashboard computes a 0–100 posture score (grades A–F) starting from 100 and deducting for open events (severity-weighted with diminishing returns), MFA gaps, and failed internal controls. It also reports MTTD/MTTR, a compliance percentage across internally-verifiable controls (mapped to ISO 27001, NIST, and CIS references), identity/access metrics, an app-managed asset inventory, and threat trends. Sections that depend on an external product (vulnerability, endpoint, email, cloud security) render an explicit **"not connected"** state rather than fabricating figures.

### 10.4 Scheduling and alerting

`routes/console.php` schedules `security:scan --quiet-ok` **every five minutes** (non-overlapping, single-server) and a history purge daily at 03:30. New findings at or above `SECURITY_ALERT_MIN_SEVERITY` (default `high`) are dispatched by `SecurityAlertDispatcher` to configured email recipients and, optionally, a Microsoft Teams webhook (which must be HTTPS). An event is only marked `alerted` once a channel actually delivers. Alerting can be disabled globally or per-run (`--no-alerts`).

---

## 11. Deployment / production hardening checklist

From `README.md` and enforced/checked by the configuration-drift detector:

- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS enforced.
- Encrypted sessions and secure cookies (`SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE`).
- All secrets (`APP_KEY`, DB, AI provider keys, integration secrets, SMTP, Teams) held in a secret manager — never committed. `.env` is git-ignored.
- `INTEGRATION_REQUIRE_HTTPS=true`, `INTEGRATION_ALLOW_PRIVATE_NETWORKS=false`.
- Supervised queue workers and scheduler; monitored failed jobs and report delivery.
- Centralised logging, backup/restore rehearsal, and egress controls.
- Run `php artisan optimize` and the full test/build suite before release.
- Automated tests always use isolated in-memory SQLite; real/production credentials are never used in tests.

---

## 12. Security configuration reference

All keys live in `config/security.php` and read from environment variables.

### Two-factor
| Key | Env | Default |
|---|---|---|
| Enabled | `MFA_ENABLED` | true |
| Required for all | `MFA_REQUIRED_FOR_ALL` | true |
| Required roles | — | administrator, security_officer |
| Verification window | `MFA_WINDOW` | 1 step |
| Recovery code count | — | 8 |
| Challenge TTL | `MFA_CHALLENGE_TTL` | 5 min |

### Password
| Key | Env | Default |
|---|---|---|
| Min length | `PASSWORD_MIN_LENGTH` | 12 |
| Max age (rotation) | `PASSWORD_MAX_AGE_DAYS` | 0 (disabled) |
| History depth | `PASSWORD_HISTORY_DEPTH` | 5 |
| Block compromised | `PASSWORD_BLOCK_COMPROMISED` | true |
| Block contextual | `PASSWORD_BLOCK_CONTEXTUAL` | true |

### Lockout
| Key | Env | Default |
|---|---|---|
| Enabled | `LOCKOUT_ENABLED` | true |
| Threshold | `LOCKOUT_THRESHOLD` | 5 |
| Backoff ladder (min) | — | 1, 5, 15, 60, 240 |
| Decay | `LOCKOUT_DECAY_MINUTES` | 60 |
| Second-factor threshold | `LOCKOUT_2FA_THRESHOLD` | 5 |

### Detection thresholds (windows in minutes)
| Detector | Env keys | Defaults |
|---|---|---|
| Global scan window | `SECURITY_SCAN_WINDOW_MINUTES` | 60 |
| Brute force | `SECURITY_BRUTE_FORCE_IP_THRESHOLD`, `SECURITY_BRUTE_FORCE_ACCOUNT_THRESHOLD`, `SECURITY_BRUTE_FORCE_WINDOW` | 10 / 6 / 15 |
| Credential stuffing | `SECURITY_STUFFING_ACCOUNT_THRESHOLD`, `SECURITY_STUFFING_WINDOW` | 5 / 30 |
| Session anomaly | `SECURITY_SESSION_IP_THRESHOLD`, `SECURITY_SESSION_WINDOW` | 3 / 60 |
| Data exfiltration | `SECURITY_EXPORT_THRESHOLD`, `SECURITY_EXPORT_WINDOW` | 25 / 60 |
| Dormant account | `SECURITY_DORMANT_DAYS` | 90 |
| After hours | `SECURITY_AFTER_HOURS_ENABLED`, `SECURITY_BUSINESS_START_HOUR`, `SECURITY_BUSINESS_END_HOUR` | true / 6 / 21 |

### Alerting and retention
| Key | Env | Default |
|---|---|---|
| Alerts enabled | `SECURITY_ALERTS_ENABLED` | true |
| Minimum severity | `SECURITY_ALERT_MIN_SEVERITY` | high |
| Recipients | `SECURITY_ALERT_RECIPIENTS` | (empty) |
| Teams enabled | `SECURITY_ALERT_TEAMS` | false |
| Alert throttle | `SECURITY_ALERT_THROTTLE_MINUTES` | 60 (declared, not implemented — see note) |
| Resolved-event retention | `SECURITY_EVENT_RETENTION_DAYS` | 365 |
| Scan-history retention | `SECURITY_SCAN_RETENTION_DAYS` | 90 |

`SECURITY_ALERT_THROTTLE_MINUTES` is read into configuration but no code
consults it, so setting it has no effect. Repeat alerting is instead prevented
by the `alerted` flag on each finding: a finding is announced once, and is
re-announced only if it is reopened. Because findings are deduplicated by
fingerprint, a persistent condition does not re-alert on every five-minute scan.
The setting is recorded as KI-020 pending a decision to implement or remove it.

### Integration network policy
| Key | Env | Default |
|---|---|---|
| Require HTTPS | `INTEGRATION_REQUIRE_HTTPS` | true |
| Allow private networks | `INTEGRATION_ALLOW_PRIVATE_NETWORKS` | false |

### External security connectors (default false → "not connected")
`SECURITY_CONNECTOR_DEFENDER`, `SECURITY_CONNECTOR_ENTRA`, `SECURITY_CONNECTOR_O365`, `SECURITY_CONNECTOR_CLOUD`, `SECURITY_CONNECTOR_VULN`.

---

## 13. Standards mapping

| Control area | Referenced standard |
|---|---|
| Multi-factor authentication | ISO 27001 A.8.5, NIST IA-2 |
| Password policy | NIST SP 800-63B, NIST IA-5 |
| Account lockout | CIS 6.2 |
| Encryption at rest | ISO 27001 A.8.24 |
| Transport security | NIST SC-8 |
| SSRF / network boundary | NIST SC-7 |
| Audit logging | ISO 27001 A.8.15 |
| Access control (RBAC) | ISO 27001 A.5.15 |
| Rate limiting | CIS 13.5 |
| Debug disabled in prod | CIS 16.11 |
| Security headers | CIS 16.x |

*Note: these are the frameworks the code references for internally-verifiable controls; this mapping documents design intent and is not a substitute for a formal external audit or certification.*

---

## 14. Known limitations and residual risk

These are honest boundaries of the current implementation, not defects:

- **Breach screening is a local list**, not a live breach API (outbound HTTP is restricted by design). It catches common passwords, not every breached credential.
- **The monitoring agent is detect-and-alert only.** It performs no automated containment; timely human response is required, so alert recipients and MTTR must be operationally owned.
- **External security telemetry** (endpoint, vulnerability, email, cloud posture) requires connectors that ship disabled; until configured, those dashboard sections are explicitly empty rather than complete.
- **Per-host lockout** intentionally does not lock accounts globally; distributed attacks rely on per-route rate limits and the credential-stuffing detector rather than lockout alone.
- **Password reset broker** tokens follow Laravel defaults (60-minute expiry) where used; review the deployed reset flow against organisational policy.

---

## 15. Reporting a vulnerability

Security issues should be reported privately to the platform owners / IT security team rather than through public channels or issue trackers. Do not include live credentials, production data, or exploit payloads against production in any report; provide reproduction steps against a controlled environment.

*(Replace this section with your organisation's formal disclosure contact and SLA.)*

---

*Generated from source review of the Ask GAHolding codebase. Reconcile against `routes/web.php`, `bootstrap/app.php`, `config/security.php`, and the `app/Services/Security` and `app/Http/Middleware` directories, which are authoritative.*
