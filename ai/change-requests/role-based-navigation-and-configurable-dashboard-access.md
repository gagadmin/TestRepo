# Change Request

## Subject

Role-Based Navigation Visibility and Administrator-Configurable Department and Platform Access for Dashboards

---

## Executive Summary

Ask GAHolding currently grants access by role permission on the server, but the user experience does not consistently reflect what a person is actually allowed to use. In the main workspace screens, menu options a user has no rights to are still displayed to them in a greyed-out state. The result is a menu that advertises capabilities the user cannot open, which generates avoidable service-desk enquiries, creates the impression of inconsistent access, and needlessly reveals which business capabilities exist inside the platform.

This Change Request proposes that navigation entries be hidden entirely when a user has no access, rather than shown as unavailable. A user will see only the parts of the platform they are entitled to use.

The second part of this request addresses dashboard visibility. Today, whether a user can see a departmental dashboard or a connected business platform is decided indirectly: partly by their role, and partly by a single free-text "Department" value recorded on their user account, matched against settings held on each dashboard, report, and data source. Because a person can hold only one department value, cross-functional staff — for example, a Finance analyst who also supports Procurement — cannot be given the combination of views their job requires without granting them a broader role than intended. Administrators also have no single place to see or set what a given individual is permitted to view.

The proposal introduces an explicit, administrator-managed access profile per user: the list of departments and the list of connected business platforms that individual is permitted to see. Dashboards, reports, and platform-specific views will then be presented strictly according to that profile. Administrators retain full visibility, and the change is additive — existing role-based rules continue to apply on top of the new profile.

The expected outcome is a cleaner, role-appropriate interface, precise and auditable control of who sees which department information, reduced access-related support effort, and stronger alignment with least-privilege expectations ahead of formal security and user-acceptance testing.

---

## Business Reason for Change

**Business challenge.** Access is enforced correctly on the server, but is presented inconsistently in the interface. Users are shown capabilities they cannot use, which produces confusion, repeated access requests, and an unnecessary disclosure of the internal capability map of the platform to every signed-in user.

**Operational need.** Departmental and platform-level visibility is currently inferred from a single department label on each user account. This cannot express the real organisational reality of shared services, matrix reporting, and staff who legitimately support more than one department. In practice this forces administrators to choose between under-granting access, where the user cannot do their job, and over-granting a role, where the user sees more than they should.

**Governance and compliance requirement.** Ask GAHolding consolidates finance, sales, procurement, operations, asset, website, and IT service data in one place. Data-minimisation and least-privilege expectations require that each person sees only the departments and source systems relevant to their role, that this decision is explicit rather than inferred, and that it can be evidenced to auditors. An explicit, administrator-maintained access profile with an audit record satisfies this; a free-text department field does not.

**Opportunity.** A clear per-user access profile becomes the foundation for safe onboarding of additional departments and source systems, because access can then be granted narrowly and verifiably as each new data source is connected.

---

## Affected Business Areas

**Departments**

- All departments currently represented in the platform: Executive, Finance, Sales, Marketing, Operations, Procurement, Asset Management, and Information Technology.

**Teams**

- IT administration and access management, which gains a new configuration responsibility.
- Information security and internal audit, as beneficiaries of clearer access evidence.
- Service desk, with an expected reduction in access-related enquiries.

**Users**

- Every signed-in user, whose menu will show fewer entries than before if their role does not cover them.
- Executives, department heads, managers, and analysts, whose dashboard selection will reflect their configured departments and platforms.
- Administrators, who gain a per-user access configuration step when creating or maintaining accounts.

**Processes**

- User onboarding, role change, and internal transfer — each now includes confirming the department and platform visibility of the user.
- Periodic access review and recertification — now supported by explicit, reportable access data.
- Dashboard and report publication — the audience of a new dashboard becomes an explicit administrative decision.

**Reports and dashboards**

- Executive, Finance, Sales, Operations, Procurement, Asset Management, Marketing, and IT service management dashboards.
- Reusable report definitions surfaced within those dashboards.
- Website analytics and IT service management views that are tied to a specific connected platform.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No.

**Justification:** Access controls are already enforced by the platform on every request. The current shortcoming is one of presentation and administrative precision, not an open door. There is no active loss of service, data integrity, or business continuity.

### Workaround Availability

**Assessment:** Yes — a partial workaround exists.

**Justification:** Administrators can approximate the desired visibility today by adjusting the role of a user and their single department value, and by editing the permitted departments recorded against individual dashboards, reports, and data sources. This is workable but manual, spread across several screens, cannot express multi-department access, and is difficult to evidence during an access review. It is adequate as a short-term measure and unsuitable as a permanent control.

### Operational Impact

**Assessment:** No unacceptable impact from deferral.

**Justification:** Deferring the change prolongs avoidable service-desk effort, continued over- or under-granting of access, and a weaker position in the pending security and user-acceptance reviews. None of these constitutes immediate financial, operational, or reputational damage.

### Timeline Constraints

**Assessment:** No.

**Justification:** The change can follow the standard assessment, development, testing, and user-acceptance route. It should, however, be completed before formal security testing and user-acceptance testing so that those exercises validate the intended access model rather than the interim one.

**Emergency Change Classification:** No

**Reason:** No immediate threat to continuity, security, or compliance; a partial workaround exists; and the normal change process can be followed without unacceptable business consequence.

---

## Risk Assessment

### Risk Level

**High**

This change alters how authorisation decisions are made and how business information is exposed to users. In line with platform governance, any change touching authentication, authorisation, permissions, or source-system access is treated as High risk until testing proves otherwise. The rating reflects the consequence of an error, not an expectation of failure.

### Risks Identified

**Business risks**

- **Over-restriction.** An access profile is configured too narrowly and a user loses a dashboard they legitimately need, delaying reporting or a management decision.
- **Under-restriction.** A migration or configuration error grants a user visibility of information belonging to another department, which would be a genuine data-exposure incident.
- **Reduced discoverability.** Because unavailable menu entries disappear rather than appearing greyed out, users may no longer realise a capability exists and therefore may not request access to it.

**Operational risks**

- **Migration of existing users.** Every current account must be translated from a single department value into an explicit access profile. Errors here affect many users at once, on the first day after release.
- **Increased administrative workload.** Onboarding and role changes gain a configuration step. If it is skipped, new users may sign in to an empty or incomplete workspace.
- **Scheduled report alignment.** Existing scheduled and delivered reports must remain consistent with the new visibility rules so that no recipient continues to receive content they may no longer view in the application.

**Security risks**

- **Interface-only enforcement.** Hiding a menu entry is a usability improvement, not a security control. If any part of the change were implemented only in the interface, protection would be weakened rather than strengthened. Server-side enforcement must remain the authority for every affected view and data request.
- **Privilege configuration abuse.** The ability to configure the data visibility of another user is itself a sensitive privilege and must be restricted, audited, and protected against self-elevation.
- **Insufficient audit evidence.** Access-profile changes that are not recorded would leave the organisation unable to evidence who granted whom access to which department information.

### Risk Mitigation Plan

1. Keep all enforcement on the server. Interface changes hide only what the server already refuses; every affected view and data request continues to be checked independently.
2. Treat the change as additive. Existing role-based permission checks remain in force; the new access profile narrows visibility further and never widens it.
3. Perform a documented migration that preserves the current effective visibility of each user as the starting point of their new profile, so no user gains or loses access silently on release.
4. Restrict access-profile configuration to administrators, retain protections against self-modification and removal of the last administrator, and record every change in the audit trail with the actor, the subject, and the before-and-after values.
5. Validate scheduled report recipients against the new rules and report any recipient whose entitlement no longer matches their subscription for business-owner decision.
6. Provide a clear, accessible message when a user reaches a view they are not entitled to, including how to request access, so hidden capability does not become inaccessible capability.
7. Complete automated permission, visibility, and data-exposure testing plus business-owner user-acceptance sign-off before production release.
8. Pilot with a small, cross-functional group of users, including at least one multi-department user, before full rollout.

---

## Expected Business Impact

### Positive Impact

- Each user sees a workspace that matches their responsibilities, reducing confusion and training effort.
- Cross-functional staff can be granted exactly the combination of departments and platforms their role requires, without being promoted into a broader role.
- Access decisions become explicit, reviewable, and reportable, which directly supports periodic access review and audit enquiry.
- The internal capability map of the platform is no longer disclosed to users who have no rights to it.
- A measurable reduction in access-related service-desk enquiries is expected.
- Additional departments and source systems can be onboarded with narrow, verifiable access from day one.

### Potential Negative Impact

- Administrators carry a new configuration responsibility at onboarding and at every role change.
- Users may temporarily perceive a loss of function where a greyed-out entry disappears, even though it was never usable.
- Incorrectly configured profiles will produce access requests in the first weeks after release; a responsive support path is required during that period.
- Users can no longer see, from the menu alone, which capabilities exist but are closed to them.

### User Impact

- Users with narrow roles will see a shorter, simpler menu.
- Users retain unrestricted access to their own account and security settings regardless of their access profile.
- Dashboard and platform selectors will list only the departments and connected platforms the user is entitled to view.
- Existing links and bookmarks to a dashboard the user is not entitled to will present a clear access message rather than a dead end.
- No user is expected to lose access they legitimately hold today, because the migration preserves current effective visibility.

### Reporting Impact

- Dashboard and report catalogues will be filtered to the configured departments and platforms of the user.
- Exported documents continue to reflect only data the requesting user was entitled to retrieve.
- Scheduled deliveries will be reviewed for alignment; any misalignment is escalated to the business owner rather than changed silently.
- Departmental reporting figures are unchanged — the calculation of information is unaffected, only who may view it.

### Compliance Impact

- Strengthens least-privilege and data-minimisation posture across consolidated enterprise information.
- Produces explicit, auditable evidence of who is permitted to view which department and which source system information, and of every change to that permission.
- Reduces the risk of unintended cross-departmental data exposure in a platform that deliberately consolidates sensitive finance, procurement, and IT service data.
- Supports the outstanding business-owner approval of information visibility and masking expectations recorded in the platform readiness assessment.

---

## Implementation Overview

The change is delivered in three coordinated parts.

**Part one — navigation reflects entitlement.** Menu entries the user is not entitled to open are removed from the navigation rather than displayed as unavailable. This is applied consistently across both the current and the modernised workspace screens, so behaviour does not depend on which screen a user happens to be on. Attempting to reach a restricted page directly continues to be refused by the platform, with a clear message and a route to request access.

**Part two — an explicit access profile per user.** Each user account gains an administrator-managed profile recording the departments and the connected business platforms that user may view. Administrators configure this alongside the existing role, department, and activation controls. Every change is written to the audit trail. Administrators themselves retain full visibility, and existing safeguards preventing self-modification and removal of the last administrator are retained.

**Part three — dashboards and platform views honour the profile.** Dashboard, report, and connected-platform selection is filtered to the configured departments and platforms of the user, in addition to the role-based permission checks already in force. Enforcement remains on the server for every listing and every data request, so the interface only reflects a decision the platform has already made.

A controlled migration converts the current effective visibility of each existing account into an equivalent explicit profile, so the release is behaviour-preserving for present users. The operating principles of the platform are unchanged: it remains read-only with respect to connected business systems, and no new data is retrieved or stored as a result of this change.

**Preservation of security invariants.** The change preserves the stated security invariants of the platform. All routes remain authenticated; functional access remains permission-based, with resource access additionally checked for ownership and visibility; state changes retain their protective controls and audit evidence; and no change is made to credential encryption, outbound request controls, or the approved read-only scope of the AI assistant. The change narrows visibility and adds audit evidence; it removes no existing control.

---

## Rollout Plan

1. **Development** — Implement navigation visibility, the per-user access profile with administrative configuration and audit recording, and profile-aware dashboard, report, and platform filtering. Prepare the behaviour-preserving migration of existing accounts. Update supporting platform documentation in the same change.
2. **Internal Validation** — Verify enforcement on the server for every affected view and data request, confirm the interface reveals nothing further, and rehearse the migration against a copy of current data with a before-and-after visibility comparison per user.
3. **QA Verification** — Execute the test cases traceable to this Change Request, covering permitted access, denied access, direct-link attempts, multi-department users, scheduled report alignment, audit evidence, and regression of unaffected reporting.
4. **User Acceptance Testing** — A cross-functional group including an executive, a departmental manager, an analyst, a multi-department user, and an administrator confirms that each sees exactly the departments, platforms, and dashboards their role requires. Business owners confirm the visibility rules for the information of their department.
5. **Production Deployment** — Deploy during an agreed low-usage window, run the access-profile migration, and confirm a sample of representative accounts before general availability. Communicate the menu change to all users in advance.
6. **Post Deployment Monitoring** — Monitor access-denied events, access-request volumes, and service-desk enquiries for an agreed stabilisation period. Review the audit trail for unexpected access-profile changes and confirm scheduled deliveries continue to reach entitled recipients only.

---

## Backout Plan

1. **Suspend new functionality** — Revert to the previous navigation behaviour and disable profile-based filtering so that visibility returns to the existing role and department rules.
2. **Restore previous application state** — Redeploy the previously released application version using the standard release process.
3. **Restore backups if required** — If the access-profile migration has caused incorrect user visibility, restore the affected account and access configuration data from the pre-deployment backup. No business, reporting, or source data is altered by this change, so no reporting content restoration is expected.
4. **Validate business operations** — Confirm that a representative set of users across every department can sign in and reach their expected dashboards and reports, and that scheduled deliveries continue to run.
5. **Notify stakeholders** — Inform administrators, department heads, the service desk, and information security of the backout, the interim access position, and the plan for reassessment and re-release.

---

## Approval Requirements

### Requestor

Name: ____________________  Signature: ____________________  Date: __________

### Department Manager

Name: ____________________  Signature: ____________________  Date: __________

### IT Manager

Name: ____________________  Signature: ____________________  Date: __________

### Business Owner

Confirms the departmental and platform visibility rules for the information they own.

Name: ____________________  Signature: ____________________  Date: __________

### CAB Approval (if applicable)

**Required.** This change alters authorisation behaviour and information visibility and is classified High risk; Change Advisory Board review is required before deployment. Information security review is required as part of CAB consideration.

Name: ____________________  Signature: ____________________  Date: __________

---

## Generated Metadata

**Generated By:** Change Request Generator

**Generated Date:** 3 September 2026

**Risk Rating:** High

**Emergency Change:** No

**Analysis Confidence:** 88%

---

# Technical Analysis Appendix

Kept deliberately brief and management-oriented.

### Affected Systems

- The Ask GAHolding web application: its navigation, the workspace and dashboard screens, and the administrative user and access management area.
- The access-control layer of the application, which decides both functional permissions and per-record visibility.
- The user and access configuration data held by the application. No connected business system is modified.

### Integrations

- Connected business platforms, including the IT service management and website analytics connectors, are affected only in that their views and selectors will be shown to fewer users. The platform remains read-only towards all connected systems, and no integration credential, connection setting, or outbound request control is changed.
- Scheduled email and Microsoft Teams report deliveries require an alignment review so that recipients match the new visibility rules.

### Security Controls

- Server-side enforcement remains the authority for every restricted page and data request; the interface change is presentational and adds no new trust in the browser.
- The new access profile narrows visibility on top of existing role permissions and never widens them.
- Configuring the visibility of another user is an administrator-only, audited action, with existing self-modification and last-administrator protections retained.
- Credential encryption, outbound request protections, and the approved read-only scope of the AI assistant are unchanged by this request.

### High-Level Architecture Impact

- Low structural impact. The change extends an existing access-control model rather than introducing a new one: an explicit per-user access profile replaces reliance on a single free-text department label, and existing visibility rules read from that profile.
- The user and access data model is extended additively; no existing information is removed or restructured, and the reporting and data-retrieval architecture is untouched.
- Follow-on work is expected to be modest: as further screens are modernised, they inherit the same declaration-driven navigation and visibility behaviour rather than repeating it.

---

## Confidence Scoring

| Dimension | Score | Basis |
| --- | --- | --- |
| Analysis Confidence | 88% | Navigation, routing, access-control, dashboard, report, and data-source visibility behaviour was reviewed directly in the application, alongside the platform architecture and feature documentation. |
| Affected Features Confidence | 90% | The affected navigation entries, dashboards, reports, and connected-platform views are enumerated explicitly in the route and dashboard configuration. |
| Business Impact Confidence | 82% | Departments, roles, and reporting domains are documented, but the number of genuinely multi-department users and current service-desk enquiry volumes have not been measured and are estimated. |
| Security Impact Confidence | 88% | Server-side enforcement points, ownership and visibility checks, and audit recording were reviewed directly; residual uncertainty concerns the outcome of the outstanding formal security test. |
| Risk Assessment Confidence | 85% | Risk drivers are well identified; the migration of existing users to explicit profiles is the principal residual uncertainty and can only be fully quantified during migration rehearsal. |

---

## Notes for Reviewers

- Part of the requested behaviour is already partially in place. The modernised navigation component already hides entries the user cannot use, and dashboards, reports, and data sources already apply role and department visibility rules on the server. The gaps this Change Request closes are: the older workspace screens still display unavailable entries in a greyed-out state, and there is no administrator-managed, multi-value department and platform access profile per user — visibility is inferred from a single free-text department label.
- **Status: approved and implemented on 3 September 2026.** The matching test case document is at `ai/test-cases/role-based-navigation-and-configurable-dashboard-access.md`. Automated coverage is in `tests/Feature/AccessProfileTest.php` (13 tests) and `resources/js/composables/useNavigation.spec.js` (4 tests); the migration is `2026_09_03_000100_add_user_access_profile_columns`. Residual risk is recorded as KI-015, KI-016, and KI-017 in `ai/issues/known-issues.md`. The remaining gates before production release are the PostgreSQL migration rehearsal, the scheduled-report recipient alignment review, and QA/UAT sign-off.
