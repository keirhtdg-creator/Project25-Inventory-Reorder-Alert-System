# Project 25 Implementation Plan

## Current stage

The repository started empty. The guide was inspected and this plan was approved before application code was written. Week 13 implementation is now present; no Week 14 or later feature has been implemented.

The guide’s Project 25 scope is the stock transaction → threshold evaluation → reorder recommendation → review → procurement status → receipt → closure workflow. Week 13 is Automation Foundation (SA7): threshold alerts, duplicate-reorder prevention, aging escalation, weekly stock-risk summaries, and the required architecture/trigger/UI/API/retry/recovery/logging/maintainability evidence.

## Confirmed decisions and remaining assumptions

- Approval authority: supervisor.
- Aging: a reorder request is eligible for escalation after seven calendar days in its current non-terminal workflow state; scan daily. Escalation after the first level still needs confirmation.
- Weekly summary: reporting period Monday–Sunday; generate every Monday at 08:00 Asia/Manila.
- Week 13 authorization: define the 401/403 contract and Flutter UI paths only. Defer authentication and role enforcement implementation to Week 14.
- Use in-app alerts; no external email/SMS provider is assumed.
- Use fictional seed inventory and demo-only actor labels; do not use real user data. Final role permissions remain a design detail to record.
- The project schema and API were verified on local MariaDB 10.4 through Laravel's MySQL driver. The installed MySQL 8 Windows service was stopped; a final check against the course-approved MySQL version remains outstanding.
- Instructor project-registration approval and final role permissions remain to be confirmed where required by the course.

Operational values that remain unspecified must be explicit configuration or documented limitations, not hidden assumptions.

## Step-by-step sequence after approval

### Phase 1 — Freeze the Week 13 design

1. Review `requirements-checklist.md` and `system-architecture.md` with the student and instructor as needed.
2. Record the confirmed project decisions above and resolve any remaining instructor-dependent questions.
3. Confirm API state transitions, role-to-action matrix, threshold behavior, reporting week, retry limits, and manual-action ownership.
4. Keep a trace from every Week 13 checklist item to an implementation task and a demonstrable acceptance scenario.

**Exit check:** no unresolved design choice can change table shape, workflow transitions, or retry behavior without being recorded.

**Status:** design recorded and student-approved before coding.

### Phase 2 — Establish the project foundation

5. Create the Flutter Web and Laravel application skeletons using supported project tooling; pin/document runtime and package versions.
6. Configure a local MySQL database with example environment settings that contain no real credentials.
7. Verify Flutter can reach the Laravel API locally and that Laravel can connect to MySQL. Add health/error reporting needed to distinguish unavailable service from a successful empty response.

**Exit check:** clean local startup instructions work using controlled data; no production secrets or personal data are present.

**Status:** Laravel/Flutter skeletons, MySQL configuration, migrations, and fictional seed data exist. Migrations/seeding and API smoke tests passed against MariaDB 10.4. MySQL 8 was not available for final compatibility validation.

### Phase 3 — Implement the normal workflow and data integrity

8. Add migrations/models for users/roles, inventory items, stock transactions, alert episodes, reorder requests, and auditable state changes.
9. Implement server-side quantity/threshold validation and an atomic stock transaction that records the transaction, updates balance, evaluates threshold, and creates/resolves an alert.
10. Implement idempotent recommendation creation with a per-item lock and active-request check so competing stock events cannot create duplicate active reorders.
11. Implement review, allowed procurement transitions, receipt recording, and explicit closure. Apply an auditable state transition and protect against partial writes.
12. Implement the API endpoints and response/error contract in `system-architecture.md`.
13. Build Flutter screens for inventory, stock transactions, alerts, reorder review/progress, and closure. Include loading, empty, validation, success, authorization, network, and server-error states.

**Exit check:** a controlled normal path reaches closure, and invalid transactions/transitions do not partially change stored state.

**Status:** API feature tests and a live HTTP smoke workflow passed. Flutter screens/build exist; complete browser-driven workflow verification remains outstanding.

### Phase 4 — Implement Week 13 automation

14. Implement a scheduled aging scan with the confirmed age rule and one-time escalation identity. Add the supervisor/manual-action queue.
15. Implement weekly risk snapshot generation with a unique reporting-period key and an API/UI view.
16. Add a bounded retry policy for confirmed transient technical failures, safe idempotency keys, terminal failure recording, and human resolution.
17. Add structured logs for each trigger decision, attempt, outcome, and human disposition. Ensure sensitive values are excluded.
18. Provide a controlled failure-injection/test method limited to local or test configuration; do not add a production failure switch.

**Exit check:** age/weekly jobs can be run repeatedly without duplicate side effects; exhausted/permanent failures are visible and actionable.

**Status:** aging and weekly schedule registrations, retry/idempotency logic, structured logs, and manual-action resolution are implemented. Transient recovery and permanent aging failure are covered by feature tests and local demonstration.

### Phase 5 — Verify and assemble Week 13 evidence

19. Test the normal end-to-end workflow using controlled data.
20. Test invalid values and invalid state transitions; verify no partial mutation.
21. Exercise the unauthorized, unavailable-service, and recovery paths in the agreed Week 13 scope; verify the UI and API outcomes.
22. Demonstrate one recovered transient failure and retain attempt logs plus exactly-once final state.
23. Demonstrate one permanent failure routed to a human, then retain evidence of the human disposition.
24. Test duplicate prevention, including repeated trigger execution and concurrent attempts; test aging escalation once and weekly summary idempotency.
25. Complete the Week 13 evidence map, update checklist states only when evidence exists, and document limitations/maintenance instructions.

**Week 13 completion gate:** all Week 13 checklist items have implementation plus repeatable evidence; no item is considered complete from documentation alone.

**Status:** Week 13 feature/submission evidence is recorded in `requirements-checklist.md`. Full Flutter/API click-through and server-side authorization remain outstanding; server authorization is deliberately deferred to Week 14 per the student's choice.

## Verification matrix

| Requirement area | Planned verification |
|---|---|
| Threshold alert | Boundary cases just above, exactly at, and below threshold; assert alert decision and stored state. |
| Duplicate prevention | Re-run the same trigger and run concurrent requests; assert at most one active reorder per item. |
| Aging escalation | Test below/at/above confirmed age limit; re-run scan; assert one escalation per level and visible human queue. |
| Weekly risk summary | Generate a completed week twice; assert one summary key and stable item snapshots. |
| Normal workflow | Exercise stock transaction, review, procurement status, receipt, and closure through Flutter/API. |
| Invalid / unauthorized | Assert appropriate 422/409 and 401/403 behavior; assert no unauthorized or invalid state mutation. |
| Unavailable service | Interrupt the local API/dependency; assert a clear Flutter error and safe retry behavior. |
| Recovered transient failure | Inject a temporary failure; assert bounded attempts, successful later run, and one final summary. |
| Permanent failure | Trigger the agreed permanent condition; assert retries stop, a manual action is created, and a human disposition is recorded. |
| Structured logs | Assert required fields and decision/error events; inspect logs for absence of secrets and sensitive payloads. |

## Explicit exclusions from this implementation stage

- Do not implement Week 14 secure integration work, Week 15 hardening scope, Week 16 performance/deployment evaluation, Week 17 UAT/final documentation, or Week 18 defense in this Week 13 sequence.
- Do not add optional external notification providers, procurement integrations, analytics, or unrelated features.
- Do not represent planned, simulated, or documented behavior as completed behavior.
