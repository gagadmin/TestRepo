# Change Request

## Subject

Paginate the Report, Connected Platform, Conversation, and Schedule Lists

---

## Executive Summary

Four screens in Ask GAHolding — Reports, Connected Platforms, AI Conversation
History, and Scheduled Reports — currently load their entire contents in a
single request every time a user opens them. There is no limit on how much is
returned. While the platform holds modest volumes this is invisible to users,
but the cost grows with every report authored, every platform connected, every
conversation held, and every schedule created. Nothing removes old records
automatically, so the volume only ever increases.

This change introduces paging to those four lists, so each request returns a
fixed number of records with simple previous and next controls. It is the same
approach already used successfully on the Users & Access and Audit Trail
screens, which handle the platform's largest lists today and are unaffected by
volume as a result.

The change is preventive rather than corrective. No user has reported a fault,
and no outage has occurred. The purpose is to stop a predictable slowdown before
it reaches the people who depend on these screens daily, and to remove an item
already recorded on the platform's own risk register.

Nothing about who may see which records changes. The rules that decide whether a
person can view a report, a platform, a conversation, or a schedule are applied
when records are selected, not afterwards, so limiting how many arrive per
request cannot widen or narrow anyone's visibility. This was confirmed against
the running code rather than assumed.

The work is contained, well-precedented within the platform, and reversible. Its
main cost is a visible change to four familiar screens, which is a
user-communication matter rather than a technical one.

---

## Business Reason for Change

**Operational need.** Every one of these four lists grows without limit and is
never trimmed. Reports and schedules accumulate as departments build their own
views; conversation history accumulates for every user who uses the AI
assistant. The time to open each screen grows with that history, and the burden
falls hardest on the most active users — analysts and administrators — because
they are the ones who generate the most records.

**Recorded risk.** This is not a newly discovered concern. It is already on the
platform's risk register as a known issue, alongside a related entry noting that
the platform lacks a standard approach to breaking long lists into pages. This
change closes the first and establishes the pattern for the second.

**Cost of delay.** The remedy is straightforward today. It becomes progressively
more disruptive the longer it waits, because the number of users with enough
history to notice the slowdown grows over time, and because a screen that has
become slow must be fixed under pressure rather than planned.

**Consistency.** Two screens in the platform already page their contents and two
categories of screen do not. Users encounter two different behaviours for what
appears to them to be the same kind of list. Aligning them removes an
inconsistency that is mildly confusing today and would be harder to justify as
the platform grows.

---

## Affected Business Areas

| Group | How they are affected |
| --- | --- |
| Analysts | Primary users of Reports, Conversation History, and Scheduled Reports. Most likely to hold enough history to notice both the current slowdown and the improvement. |
| Finance | Uses scheduled reports and exports. Sees the Reports and Schedules screens change. |
| Sales, marketing, procurement and operations | Departmental report authors and viewers. See the Reports screen change. |
| IT and administrators | Sole users of the Connected Platforms screen. Already familiar with the paging controls from Users & Access and Audit Trail. |
| Executive management | Largely unaffected. Executives consume dashboards rather than these list screens. |

**Processes affected:** report authoring and discovery, schedule management,
platform administration, and reviewing past AI conversations.

**Reports affected:** none. This changes how the *list of reports* is displayed,
not the content, calculation, or delivery of any report itself.

---

## Emergency Change Assessment

### Business Continuity

**Assessment:** No.

**Justification:** No business service is currently interrupted or degraded to
the point of failure. The concern is a gradual growth in response time, not a
loss of availability, and no user has reported an inability to complete their
work.

### Workaround Availability

**Assessment:** Yes, a workaround exists.

**Justification:** Users can continue to work as they do today. Should any list
become genuinely slow before this change is delivered, records can be archived
or removed manually as an interim measure. This is inconvenient but sufficient
to maintain service.

### Operational Impact

**Assessment:** No unacceptable impact from delay.

**Justification:** The impact of delay is a gradual, predictable increase in
screen loading time. It is measurable and reversible, and it does not threaten
data integrity, security, or regulatory standing.

### Timeline Constraints

**Assessment:** No.

**Justification:** There is no external deadline, regulatory date, or vendor
dependency forcing a shortened assessment. The change can and should follow the
normal approval process.

### Emergency Change Classification

**Not an Emergency Change. Standard Change.**

### Reason

The change is preventive maintenance addressing a recorded, non-urgent risk.
All four emergency criteria are answered in the negative. It should be scheduled
through the normal release process with full testing and stakeholder approval.

---

## Risk Assessment

### Risk Level

**Medium**

The platform's governance standard requires any change touching access control,
permissions, encryption, integrations, source-system access, or AI capability
scope to be treated as High risk until analysis proves otherwise. Analysis was
carried out for this change and is set out below: the rules governing who may
see each record are applied at the point records are selected, so limiting how
many are returned per request cannot alter anyone's visibility. On that basis
the rating is reduced to Medium, which reflects the remaining risk — a visible
change to four screens used daily across the business.

### Risks Identified

**Business Risks**

- Users accustomed to seeing an entire list at once may believe records have
  been deleted when they instead appear on a later page. This is a perception
  risk and is addressed by communication and clear on-screen totals.
- Anyone who relies on browser search to find an item within a long list will
  find it searches only the current page. Where this affects an established
  working habit, it is a genuine reduction in convenience.

**Operational Risks**

- Four screens change appearance at once. Concentrating the change increases the
  volume of user questions in the days following release, compared with a
  staged rollout.
- The screens involved are part of a single large interface file that the
  platform's own technical records identify as carrying broad regression scope.
  Changes there can affect neighbouring areas of the interface.

**Security Risks**

- The principal risk in any change of this kind is that limiting results
  interacts badly with the rules that decide who may see a record, causing
  records to be hidden from someone entitled to them or shown to someone who is
  not. Analysis confirmed that all four lists apply their visibility rules when
  selecting records rather than after the fact, so this failure mode does not
  arise by design. It must nevertheless be proven by test rather than assumed.
- No change is made to authentication, permissions, encryption, external
  integrations, source-system access, or the scope of AI capability. The
  platform's security invariants are preserved in full.

### Risk Mitigation Plan

1. Reuse the paging approach already proven on the Users & Access and Audit
   Trail screens rather than introducing a new one, so the behaviour is already
   familiar to administrators and already exercised in the platform.
2. Prove by automated test, for each of the four lists, that a user sees exactly
   the records they are entitled to and no others, both on the first page and on
   subsequent pages. Visibility must be demonstrated, not inferred.
3. Show a clear total count and current page position on every affected screen,
   so a user can immediately see that records are paged rather than missing.
4. Keep the page size generous enough that most users see their entire list on
   the first page and notice no change at all.
5. Communicate the change to analysts, finance, and administrators before
   release, noting specifically that browser search now covers the visible page.
6. Complete the platform's standard checks — style, automated tests, and a
   production interface build — together with a review of the platform's
   published interface documentation, before release.
7. Release during an agreed low-usage window, with the previous version
   available for immediate restoration.

---

## Expected Business Impact

### Positive Impact

- Report, platform, conversation, and schedule screens open in a consistent
  time that no longer grows as the business accumulates history.
- Removes a recorded risk-register item and establishes the standard approach
  the platform currently lacks for long lists, making future list screens
  cheaper and more predictable to build.
- Reduces the load each screen places on the platform, benefiting all users
  during busy periods rather than only those with large lists.
- Brings four screens into line with two that already behave this way,
  removing an inconsistency users encounter today.

### Potential Negative Impact

- A short period of user questions following release, as four familiar screens
  change at once.
- Users who habitually scan a full list visually, or search it with the browser,
  must adopt the paging controls. For a small number of heavy users this is a
  real, if minor, change to an established routine.
- Development and testing effort that could otherwise be spent on new
  capability.

### User Impact

Low to moderate, and confined to presentation. Every user retains access to
exactly the records they can reach today. Users with fewer records than the page
size — expected to be most users at present — will see no difference whatever.
Users with more will see previous and next controls together with a total count,
matching the Users & Access screen they may already use.

### Reporting Impact

None. No report content, calculation, schedule, delivery, or export is altered.
Only the list used to find and open reports changes.

### Compliance Impact

None. No change to audit evidence, retention, access control, or the handling of
personal or sensitive data. Records remain fully accessible through the paging
controls, so no information becomes unreachable.

---

## Implementation Overview

The four screens will adopt the paging approach already established elsewhere in
the platform. Each request returns a fixed number of records together with a
count of the total available and the current position, and each screen gains the
previous and next controls already in use on Users & Access and Audit Trail.

The rules determining who may view each record are unchanged and continue to be
applied when records are selected, which is what allows paging to be introduced
without affecting anyone's visibility.

Because this alters the structure of the information four screens receive, the
platform's published interface documentation and its request-collection
workflow require review as part of the change. This is called out because the
platform's own records note that this workflow cannot be relied upon to update
itself correctly and needs manual confirmation.

A secondary observation from the analysis, raised for a scoping decision rather
than included by default: alongside their main list, three of these screens also
return supporting lists — the platforms available when authoring a report, and
the reports available when creating a schedule. These are also unbounded and
grow over time, though more slowly. They are **not** included in this change.
They should be reviewed separately once the paging pattern is established, to
avoid widening the scope of a change whose value lies in being contained.

---

## Rollout Plan

1. **Development** — Implement paging on the four lists and the matching screen
   controls, together with automated tests proving both the paging behaviour and
   the unchanged visibility rules for each list.
2. **Internal Validation** — Run the platform's full standard checks: code
   style, the complete automated test suite, and a production interface build.
   Review the published interface documentation and the request-collection
   workflow against the four changed responses.
3. **QA Verification** — Verify each screen with an account holding a large
   number of records and an account holding very few, confirming that totals,
   page positions, and controls behave correctly at both extremes and at the
   exact page boundary.
4. **User Acceptance Testing** — Confirm with an analyst, a finance user, and an
   administrator that each can find and open the records they expect. Explicitly
   confirm that no record they could previously reach has become unreachable.
5. **Production Deployment** — Deploy during an agreed low-usage window.
   Communicate the change to affected groups in advance, noting the change to
   browser search behaviour.
6. **Post Deployment Monitoring** — Monitor screen response times and support
   queries for the first two weeks. Confirm with heavy users that the paged
   screens meet their needs in practice.

---

## Backout Plan

1. **Suspend new functionality** — No feature flag is required; the change is
   restored by reverting to the previous application version.
2. **Restore previous application state** — Redeploy the preceding release. The
   four screens return to loading their full lists immediately.
3. **Restore backups if required** — Not expected to be necessary. This change
   alters no stored data and makes no change to the database structure, so no
   data restoration is anticipated under any backout scenario.
4. **Validate business operations** — Confirm that all four screens load and
   that a sample user in each affected group can reach their reports, platforms,
   conversations, and schedules.
5. **Notify stakeholders** — Inform the affected groups that the previous
   behaviour has been restored, and record the reason for the backout.

---

## Approval Requirements

### Requestor

Platform engineering, on the basis of the recorded risk-register item.

### Department Manager

Required — represents the analyst and finance users most affected.

### IT Manager

Required — owns the Connected Platforms screen and the release process.

### Business Owner

Required — accepts the change to established screen behaviour for daily users.

### CAB Approval (if applicable)

Not required. This is a Standard Change at Medium risk with no change to access
control, data handling, or integrations, and with a straightforward backout.
Recommended for CAB notification rather than CAB approval.

---

## Generated Metadata

Generated By: Change Request Generator

Generated Date: 2026-09-04

Branch Name: WEB-671

Risk Rating: Medium

Emergency Change: No

Analysis Confidence: 88%

---

## Confidence Scoring

| Dimension | Score | Basis |
| --- | --- | --- |
| Analysis Confidence | 88% | The four endpoints, their visibility rules, the established paging pattern, and the single consuming interface file were all read directly in the repository. |
| Affected Features Confidence | 92% | The consuming interface file was searched for every one of the four requests; all four are used in exactly one place each. |
| Business Impact Confidence | 70% | The direction of impact is clear, but no production data volumes, user counts, or response-time measurements were available. The claim that most users fall within one page is a reasoned expectation, not a measurement. |
| Security Impact Confidence | 90% | Each of the four visibility rules was read and confirmed to be applied when records are selected. Reduced from certainty because the conclusion is drawn from reading the code, not from executing the tests that would prove it. |
| Risk Assessment Confidence | 85% | Risks follow from the confirmed technical position and from the platform's own recorded note about the broad regression scope of the interface file involved. |

---

## Technical Analysis Appendix

Included because it is the basis on which the risk rating was reduced from High
to Medium.

### Affected Systems

Four read-only listing endpoints, each requiring an active authenticated session
and a specific permission, and one interface file that is their sole consumer.

### Security Controls

The material question for a paging change is where the rule deciding who may see
a record is applied. If it is applied after records are selected, paging returns
incorrect and potentially unsafe results. All four were checked:

| List | Visibility rule | Applied at selection |
| --- | --- | --- |
| Reports | Owner, department, and role scope | Yes |
| Conversations | Restricted to the signed-in user | Yes |
| Schedules | Creator, or unrestricted for administrators | Yes |
| Connected Platforms | Permission-gated only; no per-record rule | Not applicable |

In every case the restriction forms part of the record selection itself.
Limiting the number of records returned therefore cannot alter which records a
user is entitled to reach.

### High-Level Architecture Impact

None. The change adopts an approach already present in the platform and
introduces no new component, dependency, or pattern. It makes no change to the
database structure.

### Verification Note

The security conclusion above rests on reading the current code. It must be
confirmed by the tests described in the Risk Mitigation Plan before release, and
should not be treated as evidence in itself.
