Acceptance evidence per audit item (1.2.1)
==========================================

Audit: family audit of 2026-10-27, revision 2 (development branches), §3.5 GA-01..GA-03 and the family items of §4 that
concern this plugin. Baseline `development` = `main` = f92fd2133c0a (1.2.0, 2026102700). Candidate: 1.2.1 (2026102701)
= f92fd21 plus this delivery; **it has no commit SHA yet** (the maintainer commits it to `development`).

Status classes: **code present** · **automated tests (local)** - executed by us on the local runners, dated ·
**CI passed (not executed by us)** - GitHub Actions of the maintainer · **functionally accepted (owner)** ·
**not verified**. Local evidence and GitHub CI are kept apart. Skipped tests never count as passed (all local
PHPUnit runs use `--fail-on-skipped`). Fixtures or mocks alone never count as end-to-end evidence.

Local runs of 2026-10-27
------------------------

| Cell | Static (phplint, validate, savepoints, mustache, codechecker 0 warnings, phpdoc 0 warnings) | PHPUnit | Behat (non-JS) |
|---|---|---|---|
| Moodle 4.5.15+ / PHP 8.3 / PostgreSQL 16 | OK | OK, 163 tests, 861 assertions | 10/10 scenarios, 178/178 steps |
| Moodle 4.5.15+ / PHP 8.3 / MariaDB 11.4 | OK | OK, 163 tests, 861 assertions | 10/10 scenarios, 178/178 steps |
| Moodle 5.3+ / PHP 8.3 / PostgreSQL 17 | OK | OK, 163 tests, 861 assertions, 27 PHPUnit deprecations\* | 10/10 scenarios, 178/178 steps |
| Moodle 5.3+ / PHP 8.3 / MariaDB 11.4 | OK | OK, 163 tests, 861 assertions, 27 PHPUnit deprecations\* | 10/10 scenarios, 178/178 steps |

\* PHPUnit 11 notices about metadata in docblocks (`@covers`, `@dataProvider`); the PHPUnit 9 docblock style is kept
on purpose as long as Moodle 4.5 (PHPUnit 9) is supported. No skipped, risky or failing test.

PHPUnit and static checks: 2026-10-27 (static re-run 2026-10-28 after the last changes to Behat and load scripts, 6/6 OK on 4.5 and 5.3). Behat: 2026-10-28; the 10 non-JavaScript scenarios of the plugin (5 existing, acceptance A1-A5); A6 needs JavaScript. Runner: `ltirun <45|53> <pgsql|mariadb> tool_ltigroupautoenrol all`, `ltibehat <45|53> <db> tool_ltigroupautoenrol`.
Earlier attempts of the same day failed on the event-path write budget in the shared trees (see GA-03) and were
repeated after the fix; two machine restarts interrupted runs, which were repeated, not counted.

Two-site E2E (real LTI 1.3 launches, tests/playwright/e2e/two_site.e2e.js), 2026-10-28: **7/7 passed** (E1-E7, 2.8 min; consumers site a and site a2 = Moodle 4.5/PostgreSQL 16 with separate issuers, tool site b = Moodle 5.3/PostgreSQL 17 with 1.2.1 and tool_ltidiagnostics installed). Earlier runs failed in E6 on two Moodle core defects (see GA-01) until the test recorded them.

Scale benchmark (tests/load/run_scale.sh, 10,000 users): see docs/performance.md, section "Backfill at scale".

GitHub CI: the development run 40495398729 and the main run 40410165304 of f92fd21 are green (read by the audit, not
re-run by us). **The CI of the 1.2.1 candidate does not exist yet**; it is the maintainer's next step.

GA-01 [P1] Functional Go/No-Go instead of a family gate
--------------------------------------------------------

| Requirement | Status | Code / test | Result |
|---|---|---|---|
| End-user scenarios: ≥ 2 LTI consumers, several groups, multiple enrolment, later backfill, expired enrolment, course copy/restore, real LTI launches | automated tests (local) | tests/behat/ltigroupautoenrol_acceptance.feature A1-A6; tests/playwright/e2e/two_site.e2e.js E1-E7; tests/backup_restore_test.php::test_course_copy_with_user_data | see tables above; scenario mapping in docs/ACCEPTANCE_SCENARIOS.md |
| Two consumers with separate issuers | automated tests (local) | E2E consumer 1 = site a (`https://a.platform-a.test`), consumer 2 = site a2 (`https://a2.platform-a.test`), one registration each on site b | E1 |
| 4.5 / 5.3 × PostgreSQL / MariaDB results documented | automated tests (local) | this file | real launches only into 5.3/PostgreSQL (the stack's tool site); 4.5 and MariaDB by PHPUnit and Behat |
| A6 backup/restore through the UI | code present; not verified locally | Behat A6 (`@javascript`) | runs only in the GitHub CI (no JavaScript Behat locally); backup/restore itself: backup_restore_test (7 tests, all four cells) |
| Owner's acceptance note for the exact SHA | **not verified** (owner) | docs/ACCEPTANCE_SCENARIOS.md "Acceptance note" | open: the candidate SHA and its development CI run do not exist yet |

Finding (Moodle core, not this plugin): enrol_lti's backup (`backup_enrol_lti_plugin`, 4.5 and 5.3) does not contain
`provisioningmodelearner`/`provisioningmodeinstructor`; a restored or copied LTI 1.3 tool has no provisioning mode and
every launch into it fails in `auth_plugin_lti::complete_login()` until the enrolment method is saved once. E6 records
this (asserts the missing values) and applies the user-interface workaround. Second core finding: with user data,
the copy contains enrol_lti's own user records (`enrol_lti_users`) of the copied tools but no user enrolments; a
participant who had used a tool before can not launch into the copied tool ("Cannot create duplicate LTI user", core
coding exception in `user_repository::save()`). E6 asserts this refusal and shows the assignment in the copy with
participants who had not used that tool before. Neither finding is caused or fixable by this plugin; they limit course
copies of LTI-published courses in general and are reported for the maintainer's decision (Moodle tracker).

GA-02 [P1] Non-blocking quality and performance findings
--------------------------------------------------------

| Requirement | Status | Code / test | Result |
|---|---|---|---|
| PHPMD complexity reduced or justified | automated tests (local) | docs/QUALITY.md; db/upgradelib.php `tool_ltigroupautoenrol_upgrade_analyse*()`, tests/docs_test.php, classes/local/assignment_service.php, classes/task/backfill_task.php, backup/moodle2/restore_tool_ltigroupautoenrol_plugin.class.php, classes/form/manage_lti_group_auto_enrol_form.php | production findings 11 → 2 (both justified); all refactored units covered by green tests on four cells; new: upgradelib_test::test_analyse_current_schema, ::test_analyse_without_table, manage_form_test::test_too_many_values_are_rejected |
| N+1 / DB round trips | automated tests (local) | assignment_service::get_memberships() (one prefetch per batch); scaling_test::test_repeated_backfill_query_budget (same queries for 10 and 60 participants, no writes) | green on four cells; measured at 10,000 users (docs/performance.md) |
| No unbounded materialisation | automated tests (local) | keyset batches of 500 users; prefetch ≤ 50,000 rows (`MAX_PREFETCH_ROWS`, `effective_batch_size()`); scaling_test::test_effective_batch_size | green; peak memory in docs/performance.md |
| Runtime, memory, batching, locks, concurrency on PostgreSQL and MariaDB with ≥ 10,000 users | automated tests (local) | tests/load/backfill_scale.php, tests/load/run_scale.sh | 2026-10-28, all four cells: 28,000 memberships added for 10,000 users, 7.7-8.7 reads and 3.4 writes per membership, ≤ 8.4 MB memory, repeated run 56-100 reads, 200 parallel enrolments all assigned, 0 lock waits, second backfill refused by the course lock (docs/performance.md) |
| CI gate policy for PHPMD | code present | docs/QUALITY.md | PHPMD stays advisory; workflow unchanged |

GA-03 [P2] Diagnostics coupling without dependency
--------------------------------------------------

| Requirement | Status | Code / test | Result |
|---|---|---|---|
| Events for assignments and failures (contract agreed with tool_ltidiagnostics) | automated tests (local) | classes/event/group_assignment_{base,completed,failed}.php, classes/local/event_reporter.php, triggers in classes/local/assignment_service.php (handle_new_enrolment, backfill_tool) and classes/observer.php (report_failure); docs/EVENTS.md | group_assignment_events_test (payload, names en/de, descriptions, validate_data negative cases, standard log store, backfill only for changes, aborted backfill), observer_failure_test (error boundary reports the failure event): green on four cells |
| No PII beyond ids | automated tests (local) | `validate_data()` rejects every key outside the contract | test_validate_data (e-mail and group name keys rejected), test_new_lti_enrolment_triggers_completed (no name, user name, e-mail or group name in the event data) |
| Plugin fully usable without diagnostics | automated tests (local) | no dependency, no code reference (test_no_dependency_on_family_plugins); E2E E7 with diagnostics inert | green; the plugin's own GitHub CI installs no family plugin |
| Diagnostics reads the events (with diagnostics) | automated tests (local) | E2E E7 (observations of tool_ltidiagnostics on site b) | passed: diagnostics recorded 5 observations for the 5 assignments before E7, none while inert |
| Family E2E case without and with diagnostics | automated tests (local), limited | E7: "without" = diagnostics installed but inert (`recordevents` = 0), restored afterwards; the shared stack can not uninstall it | passed (E7); `recordevents` restored to its previous value |

Finding: in the shared test trees tool_ltidiagnostics observes the new events and adds one insert per event; the
event-path query budget test therefore counts only this plugin's own observers (assignment_service_test).

Family items (§4) as far as they concern this plugin
---------------------------------------------------

| Item | Status | Evidence |
|---|---|---|
| FAM-00 branch policy documented | code present | README "Branch and release policy" |
| FAM-02 no lockstep, optional integrations degrade | code present, automated tests (local) | own version 1.2.1; no dependency; test_no_dependency_on_family_plugins |
| FAM-04 privacy, N+1, batches, locks, large courses | automated tests (local) | docs/EVENTS.md (privacy of the events), docs/performance.md |
| FAM-05 evidence separated | code present | this file (local vs. GitHub CI, no skips counted) |
