# Identity Controls Implementation

Closes three failing controls from the security dashboard's compliance register.

| Control | Framework | Before | After |
| --- | --- | --- | --- |
| Multi-factor authentication | ISO 27001 A.8.5 / NIST IA-2 | Fail — not implemented | Pass — TOTP enforced, measured by enrolment |
| Password policy and history | NIST IA-5 / SP 800-63B | Fail — Laravel defaults only | Pass — length, breach, context, reuse |
| Account lockout | CIS 6.2 | Fail — rate limit only | Pass — progressive, per account+IP |

---

## 1. Multi-factor authentication

### The invariant

For an enrolled account, **a correct password establishes no session**. This is
the property the whole control rests on, and it is asserted directly:

```php
// tests/Feature/IdentityHardeningTest.php
public function test_no_authenticated_session_exists_before_the_second_factor()
```

`AuthController::login` uses `Auth::validate()` rather than `Auth::attempt()`.
`validate()` verifies the password without touching the session. Between the two
steps the server holds one short-lived, unprivileged record:

```php
$request->session()->put('auth.two_factor_pending', [
    'user_id' => $user->id,
    'expires_at' => now()->addMinutes(5)->timestamp,
]);
```

That record grants nothing. `Auth::login()` is reached only after
`TwoFactorService::verifyChallenge()` returns true, and is immediately followed
by `session()->regenerate()` for session-fixation defence.

### Replay protection

A TOTP code is valid for a 30-second window, which means a code observed over
someone's shoulder or captured by a phishing proxy is reusable for the remainder
of that window. `users.two_factor_last_used_timestep` records the last accepted
step, and `verifyKeyNewer()` refuses anything not strictly newer.

Asserted by `test_a_totp_code_cannot_be_replayed`.

### Recovery codes

Eight codes, each usable once, stored **hashed** — a database reader cannot use
them. Consuming one removes it and audits the event with the remaining count.
The security agent raises a low-severity finding when a user drops to one or
fewer, because that user is one lost phone away from needing an administrator.

### Enrolment enforcement

Mandatory MFA is meaningless if an unenrolled session can still read data.
`EnsureTwoFactorEnrolled` confines such a session to five routes: the enrolment
flow, bootstrap, and sign-out. Everything else returns:

```json
{ "message": "Set up two-factor authentication before continuing.",
  "code": "two_factor_setup_required" }
```

The machine-readable `code` lets the SPA route to enrolment instead of showing a
generic error.

### Recovery path

A user who loses both their authenticator and their codes would otherwise be
permanently locked out, and the usual response to that is to disable the control
globally. Instead, `POST /api/admin/users/{user}/reset-two-factor` (requires
`users.manage`, fully audited) clears their factor so they re-enrol at next
sign-in. **Self-reset is refused** — otherwise a hijacked admin session could
strip its own second factor and persist.

---

## 2. Password policy

### A standards conflict, resolved explicitly

The control cites NIST IA-5, whose sub-control IA-5(1)(d) requires a maximum
password lifetime. **NIST SP 800-63B section 5.1.1.2 advises the opposite:**

> Verifiers SHOULD NOT require memorized secrets to be changed arbitrarily
> (e.g., periodically).

Forced rotation produces `Summer2024!` → `Summer2025!`. This implementation
follows 800-63B: **rotation is off by default**, and strength comes from length,
breach screening, and reuse prevention. A forced change is triggered only on
evidence of compromise.

Maximum age is nonetheless implemented. If an auditor insists on a literal
IA-5(1)(d) reading, `PASSWORD_MAX_AGE_DAYS=90` enables it with no code change,
and the compliance detail text states which stance is active and why.

### Enforced rules

| Rule | Default | Rationale |
| --- | --- | --- |
| Minimum length | 12 | 800-63B's primary strength lever |
| Composition rules | **none** | 800-63B discourages them; they shrink the search space predictably |
| Breach/common list | on | `resources/security/compromised-passwords.txt` |
| Contextual block | on | Rejects the user's own name or email local part |
| Low-entropy block | on | Catches `aaaaaaaaaaaa`, `123456789012`, keyboard runs |
| Reuse history | last 5 | Hashes only; pruned to depth |
| Periodic rotation | **off** | Per 800-63B |

The breach list is local. An online breach API (HIBP k-anonymity) would give far
better coverage, but the platform's architecture rules forbid arbitrary outbound
HTTP from application code. **This is a real coverage limitation** — the list
catches common cases, not every breached credential.

All failures are returned at once, so a user is not told about length only to hit
the breach rule on their next attempt.

---

## 3. Account lockout

### The denial-of-service problem

A naive per-account lockout is an attack primitive: anyone who knows an email
address can keep its owner permanently locked out from anywhere. A strict CIS 6.2
reading ("lock after N failures until an admin intervenes") makes this worse.

State is therefore keyed on **(account, source address, stage)**:

- An attacker locks out only their own address.
- A credential-stuffing run from one host is still shut down.
- Distributed attempts are caught by the route rate limit and the
  credential-stuffing detector.

Asserted by `test_lockout_is_scoped_to_the_source_address`.

### Progressive backoff

Five failures, then 1 → 5 → 15 → 60 → 240 minutes, the final value repeating. A
60-minute quiet period resets the counter so an honest mistake last week does not
start from a punished state.

Password and second-factor attempts have **separate budgets**, so wrong codes
cannot exhaust the password allowance. Responses use **423 Locked**, distinct
from a 429 rate limit, so the client can say something specific.

One indexed row per account+IP means the login path is a keyed read, not a
`COUNT` over `audit_logs`.

---

## Files

**Migration** `2026_07_31_000200_create_identity_hardening_tables.php`
— six `users` columns, `password_histories`, `login_throttles`.

**Services** `TwoFactorService`, `PasswordPolicyService`, `LoginThrottleService`.

**Controllers** `AuthController` (rewritten), `TwoFactorController`,
`PasswordController`, `AdminIdentityController`.

**Middleware** `EnsureTwoFactorEnrolled` (`mfa`),
`EnsurePasswordIsCurrent` (`password.current`) — applied as a route group so a
newly added endpoint is protected by default.

**Frontend** `LoginPage` (two-step), `TwoFactorSetupPage`, `ChangePasswordPage`,
`identityService`, `authStore` extensions, router gates.

**Config** `config/security.php` — `two_factor`, `password`, `lockout` blocks.

---

## Deployment

```bash
composer require pragmarx/google2fa bacon/bacon-qr-code
php artisan migrate
npm install && npm run build
php artisan config:clear
```

`.env`:

```
MFA_ENABLED=true
MFA_REQUIRED_FOR_ALL=true
PASSWORD_MIN_LENGTH=12
PASSWORD_MAX_AGE_DAYS=0        # 0 = no forced rotation (recommended)
LOCKOUT_ENABLED=true
LOCKOUT_THRESHOLD=5
```

### Rollout warning

`MFA_REQUIRED_FOR_ALL=true` forces **every** existing user through enrolment at
next sign-in. Their sessions are confined until they finish. Before enabling:

1. Tell users, with a link to the authenticator app your organisation prefers.
2. Ensure helpdesk staff know about the admin reset endpoint.
3. **Enrol at least one administrator first** — otherwise nobody can perform
   resets. Verify with `php artisan tinker`:
   `User::whereNotNull('two_factor_confirmed_at')->count()`

To stage the rollout instead, set `MFA_REQUIRED_FOR_ALL=false`; only
`administrator` and `security_officer` are then compelled.

### Existing accounts and password age

`password_changed_at` is null for pre-existing accounts. With rotation disabled
(the default) this is harmless. **If you later set a maximum age, every existing
account is immediately treated as expired** and forced to change on next
sign-in. Backfill first if that is not wanted:

```sql
UPDATE users SET password_changed_at = NOW() WHERE password_changed_at IS NULL;
```

---

## Verification

```bash
php artisan test --filter=IdentityHardeningTest   # 30 tests
npm run test                                       # includes twoFactorFlow.spec.js
```

Notable assertions:

| Test | Property |
| --- | --- |
| `test_no_authenticated_session_exists_before_the_second_factor` | The core invariant |
| `test_a_totp_code_cannot_be_replayed` | Replay protection |
| `test_a_recovery_code_works_once_only` | Single use |
| `test_recovery_codes_are_stored_hashed` | DB reader cannot use them |
| `test_secret_is_encrypted_at_rest` | Encryption verified against raw column |
| `test_lockout_is_scoped_to_the_source_address` | No DoS vector |
| `test_second_factor_attempts_have_a_separate_budget` | Budget isolation |
| `test_lockout_does_not_reveal_whether_an_account_exists` | No enumeration |
| `test_the_plaintext_password_is_never_audited` | No secret leakage into logs |
| `test_periodic_rotation_is_disabled_by_default` | 800-63B stance |

---

## Residual risks

- **Breach list coverage.** Local list only; no online breach corpus.
- **TOTP is phishable.** A real-time proxy can relay a code. WebAuthn/passkeys
  are the phishing-resistant answer and are the natural next step.
- **No step-up authentication.** Sensitive actions re-check the password but do
  not re-challenge the second factor.
- **Recovery codes are shown in-browser.** Unavoidable, but they are never
  emailed and the download is a local blob.
- **Session-wide password change.** Changing a password regenerates the current
  session but does not invalidate that user's other active sessions. Worth
  adding if compromise response becomes a priority.
