# Ask GAHolding Agent Guide

## Mission

Maintain Ask GAHolding as a secure, auditable, read-only AI business intelligence platform. Preserve authorization, data protection, source traceability, and operational reliability in every change.

## Start here

Before a material change, read the smallest relevant set:

- `ai/project-context.md` — purpose, scope, stakeholders, verified baseline
- `ai/architecture.md` — components, flows, security boundaries
- `ai/database-schema.md` — persistence, relationships, encryption
- `ai/api-contracts.md` — current HTTP contract
- `ai/coding-standards.md` — implementation and test conventions
- `ai/deployment.md` — runtime and release requirements
- `ai/features/feature-overview.md` — capability status
- `ai/issues/` — defects, risks, debt, fixes, troubleshooting
- `ai/decisions/` — durable architectural decisions
- `ai/change-requests/{branch-name}/` — approved and pending change requests for stakeholder review
- `ai/code-reviews/{branch-name}/` — post-implementation code reviews (`<name>-review.md`) plus their machine-readable `{branch-name}-review.json` metadata
- `ai/test-cases/{branch-name}/` — test case documents traceable to a change request

Governance documents are filed in a folder named for the branch the work is done on (for example `ai/change-requests/WEB-671/role-based-navigation.md`), giving Jira and Git traceability.

Treat routes, migrations, tests, and executable code as more authoritative than prose. Reconcile documentation when they differ.

## LIFT workflow

### 1. Learn

- Inspect the user-visible flow, route, request validation, controller, service/job, model, migration, and tests relevant to the change.
- Search for permissions, audit events, encrypted casts, data masking, retries, limits, and external calls.
- Check the dirty workspace and preserve unrelated user changes.
- Identify whether the change affects API contracts, schema, operations, security, or business calculations.

### 2. Intend

- State the outcome and the smallest coherent implementation.
- Identify authorization and data-exposure risks before editing.
- Define focused tests and the required regression/build checks.
- Ask for direction only when a missing choice would materially change scope, external state, security, or business behavior.
- For enhancements, bug fixes, security updates, integrations, and infrastructure changes, generate a Change Request with the `change-request-generator` skill before forging, and get stakeholder approval first. See "Change management".

### 3. Forge

- Keep controllers thin and use Form Requests plus services/jobs for behavior.
- Enforce route permissions and object ownership/visibility server-side.
- Keep integrations behind the connector registry and AI functions behind the tool allow list.
- Never add arbitrary SQL, arbitrary outbound HTTP, shell/code execution, source-system writes, or autonomous approvals without an approved architecture decision.
- Never expose or log credentials, API keys, decrypted configuration, raw sensitive payloads, session data, or full business records.
- Use additive migrations and preserve encryption compatibility.
- Keep frontend calls on the configured Axios/CSRF path and provide accessible loading/error/empty states.
- Update the relevant `ai/` documentation in the same change.

### 4. Tune

Run checks proportional to the change:

```bash
vendor/bin/pint --test
php artisan test
npm run build
composer validate --no-check-publish
php artisan route:list
php artisan schedule:list
```

- Start with focused tests, then run the full suite.
- Frontend changes require a production build.
- Route changes require `ai/api-contracts.md` and Postman workflow review.
- Schema changes require `ai/database-schema.md` and PostgreSQL rehearsal before release.
- Queue/schedule changes require schedule registration and job failure-path tests.
- External providers must be faked in automated tests; use controlled staging for live acceptance.

Report exactly what passed, failed, or could not be run. Do not claim production, load, security, recovery, vendor, email, Teams, or AI acceptance without evidence.

Where a Change Request was required, close the Tune stage with the post-implementation artifacts: generate the Code Review first, then the Test Cases. See "Change management".

## Change management

Use the `change-request-generator` skill (`.claude/skills/change-request-generator/SKILL.md`) for any change that stakeholders must approve: enhancements, bug fixes, security updates, integrations, and infrastructure changes.

- Analyze the repository and the `ai/` documentation first, then write the Change Request in business language for management and CAB readers.
- Store the Change Request at `ai/change-requests/{branch-name}/<kebab-case-subject>.md` with risk rating, emergency-change assessment, impact, rollout plan, backout plan, approvals, and confidence scores.
- Treat changes to authentication, authorization, permissions, encryption, integrations, source-system access, or AI tool scope as High risk or above until analysis proves otherwise.
- Trivial, non-functional work (typo fixes, comment or formatting changes) does not require a Change Request.

The governance sequence is Change Request → Development → Code Review → Test Cases → QA → Deployment. All three documents share one kebab-case base name **and one branch folder** for traceability; the Code Review adds a `-review` suffix and a JSON metadata sibling. Resolve the branch from, in order, the current git branch (`git rev-parse --abbrev-ref HEAD`), the pull request source branch, the Jira issue branch mapping, or a branch name the user supplies; fall back to `unknown-branch`. Sanitize it into one flat folder name by replacing `/` and `.` with `-` (`feature/ai-forecasting` → `feature-ai-forecasting`, `release/v2.5.0` → `release-v2-5-0`) — never nested directories — and create the folder if it does not exist:

1. **Change Request** — `ai/change-requests/{branch-name}/<name>.md`. Written and approved before any Forge-stage code change.
2. **Code Review** — `ai/code-reviews/{branch-name}/<name>-review.md`, accompanied by `{branch-name}-review.json`. Generated only after the Change Request is approved, development is complete, and implementation evidence exists (a merged pull request, a branch merged to staging/uat/main, or reviewable repository changes) — or when the user explicitly requests the review. Never generated alongside the Change Request. Carries a scorecard, a final implementation status (Excellent / Good / Satisfactory / Poor / Critical), and a deployment recommendation. The JSON sibling restates the same result for Jira, CI, and QA automation — review id, `reviewStatus` (`APPROVED` / `APPROVED_WITH_COMMENTS` / `CHANGES_REQUIRED` / `BLOCKED`), scores, finding counts, and branch/Jira keys — and must never disagree with the Markdown.
3. **Test Cases** — `ai/test-cases/{branch-name}/<name>.md`. Generated only after the Code Review is complete and its final status is not Critical.

A Critical code-review status means deployment is rejected; rework and re-review before test cases or release.

## Repository structure

| Path | Responsibility |
| --- | --- |
| `app/Http` | Controllers, middleware, request validation |
| `app/Services/Ai` | Provider abstraction, assistant loop, tool registry |
| `app/Services/Integrations` | Connector contracts, request policy, URL guard |
| `app/Services/Reporting` | Data retrieval, snapshots, exports, delivery |
| `app/Services/Analytics` | Deterministic advanced analytics |
| `app/Jobs`, `app/Console` | Queued delivery and scheduled dispatch/purge |
| `app/Models` | Eloquent relationships, visibility, encrypted casts |
| `database/migrations` | Authoritative schema |
| `database/seeders` | Roles, permissions, bootstrap/admin/demo metadata |
| `routes/web.php` | SPA, session-authenticated JSON routes, fallback |
| `routes/console.php` | Scheduler declarations |
| `resources/js/App.vue` | Current SPA implementation |
| `tests` | PHPUnit unit and feature coverage |
| `.claude/skills` | Repository skills, including `change-request-generator` |
| `ai/change-requests/{branch-name}` | Management-facing change requests, filed per git branch |
| `ai/code-reviews/{branch-name}` | Post-implementation code reviews and their JSON metadata, traceable to change requests |
| `ai/test-cases/{branch-name}` | Test case documents traceable to change requests |

## Security invariants

- Every application route stays authenticated/active unless intentionally public.
- Functional access is permission-based; resource access also checks ownership/visibility.
- State changes retain CSRF protection and audit evidence.
- Sensitive stored payloads retain encryption; key rotation preserves previous keys.
- External URLs retain HTTPS, redirect, unsafe-header, DNS/IP, timeout, retry, and response-size controls.
- Source output and AI output are untrusted until validated/masked.
- Provider storage remains disabled where configured, and tools remain sequential and allow-listed.
- Production does not run with local/testing environment policy.

## Working constraints

- Do not edit already-applied migrations to change shared schema; add a new migration.
- Do not delete or reset unrelated workspace changes.
- Do not use real external credentials or production recipients in automated tests.
- Do not commit `.env`, generated assets, `vendor`, `node_modules`, caches, logs, or secrets.
- Avoid a broad rewrite of `App.vue`; extract cohesive pieces incrementally.
- Avoid new unbounded list/read endpoints.
- Do not treat the Postman sync workflow as authoritative; Laravel grouped routes require review.

## Definition of done

A change is complete when behavior, security, tests, build/runtime registration, and affected documentation agree; known residual risk is recorded; and validation results are reported honestly. Where a Change Request was required, it is approved and its matching code review and test case documents exist, with the code review's final status above Critical.
