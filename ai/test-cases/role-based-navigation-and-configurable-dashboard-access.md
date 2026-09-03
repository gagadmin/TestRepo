# Test Cases

## Related Change Request

| Field | Value |
| --- | --- |
| Change Request Subject | Role-Based Navigation Visibility and Administrator-Configurable Department and Platform Access for Dashboards |
| Change Request Filename | `ai/change-requests/role-based-navigation-and-configurable-dashboard-access.md` |
| Risk Rating | High |
| Emergency Change | No |
| Implementation Date | 3 September 2026 |
| Amended | 3 September 2026 - added SEC-10 to SEC-12 and RG-10 to RG-11 after a role grant was found to override the access profile. See the amendment in the Change Request. |

---

## Objective

Confirm that:

1. Navigation entries a user is not entitled to open are not displayed to that user, in both the modernised and the legacy workspace navigation.
2. Server-side enforcement remains the authority: hiding an entry never becomes the only control.
3. An administrator can configure, per user, the departments and the connected platforms (data sources) that user may view.
4. Dashboards, reports, and platform selectors present only the departments and platforms in the user access profile.
5. Every access-profile change is recorded in the audit trail with actor, subject, and before/after values.
6. Existing users retain their current effective visibility after migration (behaviour-preserving release), with the single deliberate exception in RG-10: holders of the `executive` role lose departmental content outside their configured departments, because that visibility was a defect.
7. A role grant never substitutes for the access profile on a department-scoped dashboard or report, while the intentional cross-cutting grant used by the Security dashboard keeps working.

---

## Scope

**In scope**

- Legacy and modernised sidebar navigation visibility.
- Per-user access profile storage, validation, administration API, and administration UI.
- Dashboard listing and detail visibility, report visibility, and data-source accessibility.
- Audit evidence for access-profile changes.
- Migration backfill of existing accounts.

**Out of scope**

- Report calculation logic and KPI values (unchanged).
- Credential encryption, outbound request guards, AI tool allow list (unchanged).
- Live SMTP / Microsoft Teams delivery acceptance (separate environment acceptance).

---

## Test Scenarios

### Happy Path Tests

| ID | Scenario | Steps | Expected |
| --- | --- | --- | --- |
| HP-01 | Entitled navigation shown | Sign in as an analyst holding `dashboards.view`, `reports.view`, `ai.chat` | Overview, Ask GAHolding, Dashboards, Reports appear in the workspace navigation |
| HP-02 | Administration group shown to administrator | Sign in as administrator | Data sources, AI tools, Users & access, Security, Audit trail all appear |
| HP-03 | Multi-department profile grants both | Administrator sets a user profile to `["Finance","Procurement"]`; user reloads Dashboards | Both the Finance and the Procurement dashboards are listed |
| HP-04 | Platform allow-list grants the permitted source | Administrator grants the user one Freshservice source only; user opens Dashboards | Only that Freshservice source appears in the platform selector, and its data loads |
| HP-05 | Administrator retains full visibility | Sign in as administrator with an empty profile | All active dashboards, reports, and connected sources remain visible |
| HP-06 | Profile configuration persists | Administrator saves departments and platforms for a user, reopens the user | The saved selections are shown |
| HP-07 | Owner keeps own source | A non-administrator who owns a data source has a profile excluding it | The owner can still access their own source |
| HP-08 | Empty profile falls back to the department label | User with `allowed_departments` unset and `department = "Sales"` | Sales dashboards remain visible (behaviour preserved) |

### Negative Tests

| ID | Scenario | Steps | Expected |
| --- | --- | --- | --- |
| NG-01 | Unentitled navigation hidden | Sign in as a user without `analytics.view` | No Advanced analytics entry is rendered anywhere in the navigation (not greyed out, not present) |
| NG-02 | Legacy navigation hidden | As NG-01, on a legacy workspace view | The legacy sidebar renders no disabled placeholder for the restricted view |
| NG-03 | Direct URL to a restricted page | Navigate directly to `/analytics` without `analytics.view` | Redirected/refused with a clear access message; no analytics data is returned |
| NG-04 | Direct API call to a restricted route | Call the analytics endpoint without `analytics.view` | HTTP 403; no payload |
| NG-05 | Dashboard outside profile | User whose profile omits Procurement requests the Procurement dashboard by slug | HTTP 404/403; no dashboard content, no report rows |
| NG-06 | Data source outside profile | User requests dashboard data for a source not in their platform allow-list | HTTP 403; no source data returned |
| NG-07 | Invalid profile input | Administrator submits a non-existent data source id, a non-array value, or over-long department names | HTTP 422 with field errors; nothing persisted |
| NG-08 | Non-administrator cannot configure | User without `users.manage` submits an access-profile update | HTTP 403; profile unchanged |
| NG-09 | Self-elevation blocked | Administrator attempts to remove their own administrator role or deactivate themselves | Validation error; unchanged |
| NG-10 | Last administrator protected | Attempt to demote the only active administrator | Validation error; unchanged |

### Security Tests

| ID | Area | Test | Expected |
| --- | --- | --- | --- |
| SEC-01 | Authentication | Call every affected route unauthenticated | Redirect/401; no data |
| SEC-02 | Permission enforcement | For each affected route, call with a role lacking the required permission | 403 for every route; enforcement present server-side independent of the interface |
| SEC-03 | Visibility enforcement | Confirm dashboard, report, and data-source listings are filtered in the query/server layer, not in the browser | Restricted records never appear in the HTTP response body |
| SEC-04 | Ownership check | Confirm resource access still checks ownership in addition to permission | Non-owner without visibility is refused |
| SEC-05 | Audit evidence | Change a user access profile | An audit record exists with the acting administrator, the subject user, the event, IP, user agent, and before/after departments and platforms |
| SEC-06 | Data exposure / masking | Inspect the users administration response and the bootstrap response | No password hash, two-factor secret, recovery code, or decrypted source credential is present |
| SEC-07 | Privilege boundary | Verify only `users.manage` holders can read or write another user access profile | Others are refused |
| SEC-08 | Interface is not the control | With the navigation hidden, forge the request directly | The server still refuses; hiding adds no trust in the browser |
| SEC-09 | No widening | Confirm a profile cannot grant a department or platform the user role permission does not already allow | Role permission continues to gate functional access |
| SEC-10 | Role grant does not override the profile | Sign in as a non-administrator holding the `executive` role whose profile lists Marketing only, and list dashboards and reports | Only Marketing departmental content plus the enterprise Executive dashboard and Executive KPI report; Finance, Sales, Operations, Procurement, Asset Management and IT service management are absent from the response body |
| SEC-11 | Intentional role grant preserved | Sign in as a `security_officer` whose profile does not include Information Technology | The Security dashboard remains reachable: narrowing the executive grant must not disable the cross-cutting grant mechanism |
| SEC-12 | No department-scoped record grants `executive` | Provision a fresh environment and inspect every dashboard and report with department scope | No `allowed_roles` list on a department-scoped record contains `executive`; enterprise records are exempt |

### Regression Tests

| ID | Area | Expected |
| --- | --- | --- |
| RG-01 | Authentication, activation, forced password change, two-factor enrolment | Unchanged |
| RG-02 | Report generation, PDF export, Excel export | Unchanged content for an entitled user |
| RG-03 | Scheduled reporting dispatch and delivery jobs | Continue to run; recipients reviewed for alignment |
| RG-04 | AI chat, tool calls, citations, conversation history | Unchanged |
| RG-05 | Data source creation, editing, connection test | Unchanged |
| RG-06 | Audit trail filtering and pagination | Unchanged, plus the new event type |
| RG-07 | Existing automated suite | Passes in full |
| RG-08 | Production frontend build | Succeeds |
| RG-09 | Migration | Additive only; existing effective visibility preserved for every seeded and existing account, with the single deliberate exception in RG-10 |
| RG-10 | Executive visibility reduction | Expected change, not a regression: an account holding the `executive` role no longer sees departmental dashboards or reports outside its configured departments. Confirm each such account has had its departments configured and confirmed with the business before release |
| RG-11 | Role-grant migration reversibility | Roll the role-grant migration back and forward again | The `allowed_roles` lists return to their prior contents and then to the corrected contents; no other field is altered |

### User Acceptance Tests

| ID | Persona | Acceptance |
| --- | --- | --- |
| UAT-01 | Executive | Sees the enterprise KPI view; sees departmental views such as Finance only where those departments are in their configured profile; sees no administration entries |
| UAT-02 | Department manager | Sees only their own department dashboards and reports |
| UAT-03 | Analyst | Sees the reporting and AI capabilities their role permits, nothing more |
| UAT-04 | Multi-department user | Sees exactly the combination of departments configured, without a broader role |
| UAT-05 | Administrator | Can configure another user departments and platforms in one place and see the result immediately |
| UAT-06 | Business owner | Confirms the visibility rules for the information they own |
| UAT-07 | Service desk | Confirms the access-denied message tells the user how to request access |

---

## Expected Results

- Navigation renders only entitled entries; no disabled placeholders remain.
- Every restricted page and data request is refused server-side with 403/404 as appropriate.
- Dashboard, report, and platform listings contain only records permitted by role permission **and** the user access profile.
- Administrators are unaffected by profile narrowing.
- Access-profile changes appear in the audit trail with full before/after evidence.
- The migration is additive and behaviour-preserving.
- `vendor/bin/pint --test`, `php artisan test`, and `npm run build` all pass.

---

## Pass/Fail Criteria

**Pass** requires all of:

- All Security tests pass with no exception.
- All Negative tests return the expected refusal with no data leakage.
- All Happy Path and Regression tests pass.
- Full automated suite green; production build green; Pint clean.
- UAT sign-off from executive, manager, analyst, multi-department, and administrator personas plus business owner confirmation.

**Fail** on any of:

- Any restricted record appearing in an HTTP response body.
- Any affected route enforcing access only in the interface.
- Any access-profile change without an audit record.
- Any existing user losing legitimate access after migration.

---

## Test Execution Checklist

- [ ] Migration rehearsed with per-user before/after visibility comparison
- [ ] Happy Path HP-01 … HP-08
- [ ] Negative NG-01 … NG-10
- [ ] Security SEC-01 … SEC-09
- [ ] Regression RG-01 … RG-09
- [ ] `vendor/bin/pint --test`
- [ ] `php artisan test` (focused, then full suite)
- [ ] `npm run build`
- [ ] `php artisan route:list` reviewed against `ai/api-contracts.md`
- [ ] Scheduled report recipient alignment review completed
- [ ] UAT-01 … UAT-07 signed off
- [ ] Audit trail reviewed for unexpected access-profile changes

---

## Sign-Off

### QA Lead

Name: ____________________  Signature: ____________________  Date: __________

### Business Owner

Name: ____________________  Signature: ____________________  Date: __________

### UAT Sign-Off

Name: ____________________  Signature: ____________________  Date: __________

---

## Traceability

| Link | Reference |
| --- | --- |
| Change Request | `ai/change-requests/role-based-navigation-and-configurable-dashboard-access.md` |
| Automated coverage | `tests/Feature/AccessProfileTest.php`, `tests/Feature/AuthorizationTest.php`, `tests/Feature/DashboardReportingTest.php`, `tests/Feature/UserProvisioningTest.php` |
| Frontend coverage | `resources/js/tests/`, `resources/js/router/routes.spec.js` |
| External providers | Faked in all automated tests, per `ai/coding-standards.md` |
| SEC-10 / SEC-11 / SEC-12 coverage | `tests/Feature/AccessProfileTest.php` - `test_role_grant_does_not_widen_a_department_scoped_dashboard`, `test_role_grant_does_not_widen_a_department_scoped_report`, `test_intentional_role_grant_still_bypasses_the_profile`, `test_no_seeded_department_scoped_record_grants_the_executive_role` |
| RG-11 coverage | `database/migrations/2026_09_03_000200_restrict_department_dashboard_role_grants.php` (reversible; exercised by the suite on every run) |
