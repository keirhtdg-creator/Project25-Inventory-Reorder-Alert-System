# Project 25 Requirements Checklist

## Source and project boundary

Source: *35 Student Project Options: Continuous Development for Weeks 13–18*, Project 25, “Inventory Reorder Alert System.”

The guide describes an office or laboratory that needs early warning when approved supplies fall below thresholds. Its primary users are custodians, requesting personnel, purchasing staff, and supervisors. The bounded workflow is stock transaction → threshold evaluation → reorder recommendation → review → procurement status → receipt → closure.

This checklist tracks the approved plan and implementation evidence. A checked box means the item has corresponding documentation/code and the listed automated or smoke evidence; it does not imply instructor approval or final course acceptance.

## Week 13 — Automation Foundation (SA7)

### Required features

- [x] **W13-F1 — Threshold alerts:** Evaluate approved inventory after a stock change and create an alert when available stock is at or below its configured threshold.
- [x] **W13-F2 — Duplicate-reorder prevention:** Do not create a second active reorder recommendation for the same item while one is already active.
- [x] **W13-F3 — Aging escalation:** Identify an aging, unfinished workflow and route an escalation to a supervisor/human-action path.
- [x] **W13-F4 — Weekly stock-risk summaries:** Generate a scheduled weekly summary of stock at risk.

### Required Week 13 submissions

- [x] **W13-S1 — Workflow boundary:** Document included actors, states, actions, and explicit exclusions.
- [x] **W13-S2 — Trigger decision:** Explain each event/schedule trigger, its decision rule, and how repeated execution is made safe.
- [x] **W13-S3 — Flutter Web screen flow:** Show the user journey and required loading, success, empty, validation, authorization, network, and server-error states.
- [x] **W13-S4 — API contract:** Define endpoints, request/response data, validation, and error responses.
- [x] **W13-S5 — Retry and recovery policy:** Distinguish retryable from permanent failures, bound retries, and state how work resumes safely.
- [x] **W13-S6 — Structured logs:** Define machine-readable operational events and correlation fields without sensitive data.
- [x] **W13-S7 — One recovered transient failure:** Demonstrate and retain evidence of a temporary failure that succeeds after retry.
- [x] **W13-S8 — One permanent failure routed for human action:** Demonstrate a non-retryable failure, its durable escalation, and its human disposition.
- [x] **W13-S9 — Maintainability note:** Explain how the automation can be understood, tested, operated, and changed safely.

## Shared Project 25 workflow and application requirements

These are stated in the Project 25 bounded workflow and the guide’s common scope requirements. Their implementation status is shown individually below.

- [x] **P25-1 — Complete normal path:** Demonstrate a stock transaction through threshold evaluation, recommendation, review, procurement status, receipt, and closure. Verified by API feature test and live API smoke workflow.
- [x] **P25-2 — Controlled data:** Use fictional, anonymized, or otherwise instructor-approved inventory and user data; do not use production secrets or unapproved personal information.
- [ ] **P25-3 — Flutter Web workflow:** Provide responsive screens for the minimum complete workflow. Screens are implemented and the inventory dashboard was visually checked at desktop/mobile sizes; complete workflow click-through is pending.
- [x] **P25-4 — Backend API:** Apply server-side validation and auditable state changes.
- [ ] **P25-5 — Authentication and role-based authorization:** Enforce access according to user role. Week 14 calls out secure integration; this document specifies expected boundaries but does not implement it.
- [x] **P25-6 — Scheduled or event-driven automation:** Use bounded retries, recovery, structured logs, and manual handling for permanent failures.
- [ ] **P25-7 — Required adverse paths:** Demonstrate invalid input, unauthorized access, unavailable service, and recovery, in addition to the normal path. Invalid, unavailable, and recovery behavior are tested; the 403 Flutter state is tested against a mock response only because server auth is deferred.
- [x] **P25-8 — Reproducible evidence:** Preserve test and demonstration evidence for the workflow and required Week 13 automation.
- [x] **P25-9 — Bounded purpose and authority:** Identify the organizational purpose, users, and authorized decision maker; confirm who has approval authority before enabling reorder approval.

## Planned scenarios and acceptance evidence

| Scenario | Expected behavior | Evidence required before marking complete | Current evidence/status |
|---|---|---|---|
| Normal | Record a stock issue that brings an item to/below threshold; show one alert and recommendation; review it; update procurement status; record receipt; close it. | Repeatable end-to-end demonstration and persisted state/audit evidence. | Passed Laravel API feature test and live HTTP smoke workflow; full Flutter click-through remains unverified. |
| Invalid | Reject a non-positive transaction quantity, invalid threshold, invalid state transition, or malformed request; do not partially change stock or workflow state. | API response, UI validation/error state, and unchanged database state. | Negative stock issue returned 422 and left stock unchanged in feature test; Flutter form validation is implemented. |
| Unauthorized | A user without the required role receives 401 (not authenticated) or 403 (authenticated but forbidden); no protected state changes. | Role matrix, request/response evidence, and unchanged database state. Actual auth implementation is not part of this planning-only task. | Flutter 403 state tested with a mock response. Backend access control is intentionally deferred to Week 14. |
| Unavailable service | If a required backend/API dependency is unavailable, show a clear retryable error; do not claim a save or create duplicate work. | UI network/server-error state and backend/job log evidence. | Flutter 503 state tested with a mock response; local CORS preflight and API health were smoke-tested. |
| Recovered transient failure | A temporary, simulated failure while generating a weekly summary is retried; a later attempt succeeds exactly once. | Attempt logs, one summary for the period, and successful completion evidence. | Feature test verified attempt 1 retry, attempt 2 success, one summary and one snapshot row; local CLI demonstration succeeded. |
| Permanent failure | A missing supervisor owner/mapping prevents automated assignment; stop retrying and create a visible manual-action item for a human to assign the owner and resolve the escalation. | Terminal failure log, durable manual-action item, human disposition, and no repeated retry loop. | Feature test and local aging command created one supervisor manual action; API can resolve it. |

## Scope guard

- The repository now contains the planning documents and Week 13 application implementation. Use the checklist above to distinguish verified evidence from pending work.
- Week 14 (secure integration), Week 15 (testing and hardening beyond required Week 13 evidence), Week 16 (performance/deployment evaluation), Week 17 (UAT/documentation completion), and Week 18 (technical defense) are not implementation tasks in this plan.
- The guide’s later-week outcomes remain visible as boundaries, not as Week 13 acceptance criteria.
- The student confirmed supervisor approval, a seven-calendar-day aging limit, and Monday-to-Sunday summaries generated Monday at 08:00 Asia/Manila. These are project decisions, not values prescribed by the guide. Authentication implementation remains deferred to Week 14.

## Status

The Week 13 features and required submissions have implementation or reproducible test evidence. P25-5 and the server-enforced portion of P25-7 remain intentionally incomplete because Week 14 authentication/role enforcement was explicitly deferred. Instructor approval and final course acceptance are not claimed.
