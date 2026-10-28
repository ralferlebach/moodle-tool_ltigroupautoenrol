Functional acceptance: end-user scenarios (GA-01)
=================================================

Purpose: basis for the maintainer's **own** functional acceptance (Go/No-Go) of tool_ltigroupautoenrol, separately
from the other plugins of the LTI tool family (audit 2026-10-27, item GA-01, §5 "Release getrennt").

Subject of the acceptance
-------------------------

| | |
|---|---|
| Baseline | `development` = `main` = **f92fd2133c0a** (1.2.0, build 2026102700); its development CI run 40495398729 and main CI run 40410165304 are green (read by the audit, not re-run by us) |
| Candidate | **1.2.1 (2026102701)** = f92fd21 **plus the changes of this delivery** (docs/CHANGES.md, entry 1.2.1). The candidate has no commit SHA yet: the maintainer commits it to `development`; the acceptance note must then name that new SHA and its development CI run |
| Not changed against f92fd21 | database schema, configuration, capabilities, settings page and backfill page behaviour, CI workflow |
| Changed against f92fd21 | two new events (GA-03), backfill prefetches memberships per batch (GA-02), refactorings without behavioural change (GA-02), new tests and documents |

How to accept
-------------

1. Read the scenarios below; each one says what a teacher or participant does and what must be observed.
2. Check the automated evidence (section "Results"). Automated runs are evidence for the maintainer, **not** the
   acceptance itself.
3. Optionally repeat scenarios E1, E5 and E6 by hand on a test site with two LTI consumers (the manual steps are the
   same as in the automated test).
4. Record the decision in the table "Acceptance note" with the exact `development` SHA of the candidate.

Scenarios
---------

Two kinds of automated evidence exist for each scenario:

* **Behat** (tests/behat/ltigroupautoenrol_acceptance.feature, scenarios A1-A6): one Moodle site; LTI enrolments are
  created with enrol_lti's enrolment API (the same `user_enrolment_created` event and learner role as a launch); all
  teacher actions through the user interface. Runs on every supported Moodle version and database.
* **Playwright, two sites** (tests/playwright/e2e/two_site.e2e.js, scenarios E1-E7): **real LTI 1.3 launches** from two
  consumers on site A (Moodle 4.5, platform) into one shared course on site B (Moodle 5.3, tool with this plugin),
  OIDC login, JWT, auto-provisioning of the LTI users, teacher actions in the browser on B.

| ID | Scenario (end-user view) | Expected | Behat | Two-site E2E |
|---|---|---|---|---|
| S1 | Two LTI consumers use the same course; each consumer's tool is mapped to its own groups plus one shared group | Participants of consumer A land in "Uni A", "Uni A lab", "All consumers"; of consumer B in "Uni B", "All consumers"; never in the other consumer's groups | A1 | E1 |
| S2 | Several groups per tool | All mapped groups are assigned at once | A1 | E1, E3 (`added` = 3 / 2) |
| S3 | Multiple enrolment: one person enrolled through both tools | Member of the groups of both tools; the shared group only once | A1 | E1 (one user on B, two enrolments), E3 (`added` 1, `existing` 1) |
| S4 | Teacher sees the result | Group sizes on the course's groups page | A1 | E2 |
| S5 | Repeated launch | Nothing changes, nothing is logged twice | - | E4 |
| S6 | Later backfill: participants enrolled before the tool was mapped | Preview shows the missing memberships; after confirmation and cron they are assigned; second preview: nothing to do | A2, A4 | E5 |
| S7 | Expired enrolment (enrolment period of the tool elapsed), suspended and not yet started enrolments | Not assigned, neither on enrolment nor by the backfill; not counted in the preview | A3 | E5 (real expiry: enrolment period 20 s) |
| S8 | Mapping changed later | Existing memberships stay (additive); new groups are filled by the backfill | A4 | - |
| S9 | Course copy ("Copy course" with user data) | The copy maps its own copied tools to its own copied groups; enrolments into the copy are assigned in the copy only. Core copies no enrol_lti user enrolments. Two **Moodle core defects** limit launches into a copy (see docs/ACCEPTANCE.md, GA-01): copied tools have no provisioning mode until the enrolment method is saved once, and participants who had used a tool can not launch into its copy | A5 | E6 (records both core defects) |
| S10 | Course backup and restore into a new course | Restored tools mapped to restored groups; enrol_lti restores no user enrolments, new enrolments are assigned | A6 (JavaScript, GitHub CI only) | - |
| S11 | Events for diagnostics (GA-03) | Every assignment logged with ids and counters only; failures logged with a machine code | - | E3, E5, E7 |
| S12 | Plugin without / with tool_ltidiagnostics | Identical assignment; diagnostics only reads | - | E7 (diagnostics inert vs. active on B) |

Results
-------

Executed locally on 2026-10-28 for the candidate 1.2.1 (2026102701), not on a commit SHA (see "Subject"):

| Evidence | 4.5 / PostgreSQL | 4.5 / MariaDB | 5.3 / PostgreSQL | 5.3 / MariaDB |
|---|---|---|---|---|
| Behat A1-A5 (with the plugin's other non-JS scenarios: 10 scenarios, 178 steps) | passed | passed | passed | passed |
| Behat A6 (JavaScript) | not run locally (GitHub CI) | - | - | - |
| Two-site E2E E1-E7, real launches (consumers: 4.5/PostgreSQL sites a and a2; tool: site b) | - | - | **7/7 passed** | - |
| PHPUnit incl. course copy, backup/restore, events (163 tests) | passed | passed | passed | passed |
| 10,000-user backfill (docs/performance.md) | passed | passed | passed | passed |

Observed on site b in E1-E7: alice and carol (consumer a) in "Uni A", "Uni A lab"; bob (consumer a2) and carol in
"Uni B"; all three in "All consumers"; carol is one user on b with two LTI enrolments; a second launch changes
nothing; erin's enrolment from before the mapping is assigned by the confirmed backfill (preview 1 missing), dave's
expired enrolment (enrolment period 20 s) is neither counted nor assigned; the copied course maps its own tools to
its own groups and assigns new launches there; each assignment appears once as `group_assignment_completed` in the
log; tool_ltidiagnostics observed them and observed nothing while set inert.

Known limits of the evidence
----------------------------

* The two-site stack has one platform host (site A, issuer `https://a.platform-a.test`). The two consumers are two
  separate LTI 1.3 registrations of it (own client id, deployment and tool type each), not two different issuer hosts.
  On site B they are two distinct platform registrations; the plugin maps by published tool, so this is the
  relevant separation. A user of A who launches through both consumers is one user on B (same issuer and `sub`),
  which is exactly the multiple enrolment case S3.
* Real launches run only into Moodle 5.3 with PostgreSQL 17 (site B of the stack). Moodle 4.5 and MariaDB are covered
  by PHPUnit and Behat with enrol_lti's enrolment API, not by launches.
* "Without diagnostics" in the two-site stack means tool_ltidiagnostics installed but inert (`recordevents` = 0); the
  shared stack can not uninstall it. The plugin's own GitHub CI installs no other family plugin and is the evidence
  for a site without diagnostics.
* Behat scenario A6 (backup/restore through the user interface) needs JavaScript and runs only in the GitHub CI;
  backup/restore itself is covered by PHPUnit (backup_restore_test) on all four local cells.

Acceptance note (to be filled in by the maintainer)
---------------------------------------------------

| Field | Value |
|---|---|
| Plugin / release | tool_ltigroupautoenrol 1.2.1 (2026102701) |
| `development` SHA of the candidate | |
| Development CI run of that SHA (green) | |
| Scenarios checked by hand (optional) | |
| Decision (Go / No-Go) and date | |
| Name | |
