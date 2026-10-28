Code quality gates and PHPMD policy
===================================

Audit item GA-02 (family audit of 2026-10-27, development SHA f92fd21): the CI log of f92fd21 contains PHPMD
complexity findings (`tool_ltigroupautoenrol_upgrade_analyse()` NPath 448 / cyclomatic 12, `tests/docs_test.php`,
`classes/local/assignment_service.php`) although the whole CI run is green. This file explains why, what 1.2.1 changed
and which findings are tolerated.

CI gate policy
--------------

| Check (job `test` of .github/workflows/moodle-plugin-ci.yml) | Gate? | Why |
|---|---|---|
| PHP lint, Moodle code checker (`--max-warnings 0`), PHPDoc checker (`--max-warnings 0`), validate, savepoints, mustache, grunt | **blocking** | objective rules of the Moodle coding style and plugin contract |
| PHPUnit (all supported branches, PostgreSQL and MariaDB), Behat, upgrade test, Playwright, release artefact, install smoke test | **blocking** (via the release gate `ci-complete`) | functional evidence |
| PHPMD (`moodle-plugin-ci phpmd`, rule set of moodle-plugin-ci) | **advisory** (`continue-on-error: true`) | heuristic metrics; moodle-plugin-ci 4 marks phpmd and phpcpd as deprecated; thresholds (cyclomatic 10, NPath 200, 10 public methods per class) are not Moodle rules and flag ordinary PHPUnit test classes |
| phpcpd (copy/paste detector) | advisory | same reason |

Policy (unchanged in 1.2.1, workflow not modified):

1. PHPMD stays advisory. A PHPMD finding never blocks a merge or release by itself.
2. Every PHPMD finding in **production code** (everything that ships in the release ZIP) is either removed or listed below
   with a reason. New production findings in a pull request need the same: fix or add a reasoned entry here.
3. Findings in **test code** of the kinds `TooManyPublicMethods`, `CouplingBetweenObjects`, `BooleanArgumentFlag` and
   `UnusedFormalParameter` (signature of an overridden test seam or observer callback) are tolerated without an entry:
   a PHPUnit test class groups the tests of one unit, and splitting it only for the metric would scatter the tests.
4. Complexity is reduced only where the code is covered by tests that pin its behaviour (refactoring without
   behavioural change). The CI agent owns the workflow; turning PHPMD into a gate would be a separate owner decision.

Local check: `vendor/bin/phpmd <plugin> text <moodle-plugin-ci>/res/config/phpmd.xml` (moodle-plugin-ci 4.x, PHPMD 2.15).
**Pitfall:** PHPMD/pdepend keeps a parse cache in `~/.pdepend`; a stale cache reported a refactored method with its old
complexity in our runs. Run with an empty cache (e.g. `HOME=$(mktemp -d)`) before trusting a result.

Result of 1.2.1 (measured 2026-10-27, PHPMD 2.15.0, rule set of moodle-plugin-ci 4, empty pdepend cache)
-----------------------------------------------------------------------------------------------------------

| | f92fd21 (1.2.0) | 1.2.1 |
|---|---|---|
| findings in production code | 11 | 2 |
| findings in test code | 11 | 13 (new test classes: events, course copy) |
| total | 22 | 15 |

Reduced (each covered by tests that ran green before and after the change):

| Location (f92fd21) | Finding | Change in 1.2.1 | Tests pinning the behaviour |
|---|---|---|---|
| db/upgradelib.php `tool_ltigroupautoenrol_upgrade_analyse()` | cyclomatic 12, NPath 448 | split into one function per check (`_analyse_legacy`, `_analyse_orphans`, `_analyse_duplicates`, `_analyse_mappings`); output lines and order unchanged | upgradelib_test::test_analyse_is_read_only (0.1 schema), new test_analyse_current_schema (exact report of orphan + invalid mapping), new test_analyse_without_table; real CLI run in the CI job upgrade-e2e |
| tests/docs_test.php `test_workflow_references_exist()` | cyclomatic 13, NPath 448 | reference collection moved into two private helpers; same files, same regular expressions | the test itself (repository checkout) |
| classes/local/assignment_service.php `backfill()` | CountInLoopExpression | count stored per batch; per-tool loop in `backfill_tool()`; membership prefetch per batch (see docs/performance.md) | assignment_service_test (11 tests incl. budgets), scaling_test, backfill_task_test, events test |
| classes/task/backfill_task.php `execute()` | cyclomatic 13, NPath 780 | split into `execute()`, `get_confirmed_config()`, `process()`; statuses, messages and exceptions unchanged | backfill_task_test (10 tests: queued, skipped, partial, failed, locked, retry, clearing of event errors) |
| backup/moodle2/restore_tool_ltigroupautoenrol_plugin.class.php `after_restore_course()` | cyclomatic 13, NPath 624 | split into `translate()` and `store()` | backup_restore_test (7 tests incl. new course copy) |
| classes/form/manage_lti_group_auto_enrol_form.php `validation()` | cyclomatic 15, NPath 304 | split into `has_foreign_tool_select()`, `is_valid_selection()`, `is_empty_selection()`, `is_course_group()`; same accepted and rejected values | manage_form_test (12 tests: foreign groups, deleted and unknown tools, scalar and non-numeric values, empty-select placeholder, duplicates, size limit - the last one added in 1.2.1) |
| backup/moodle2/... | UnusedLocalVariable `$DB` | removed | backup_restore_test |

Tolerated in production code (reasoned):

| Location | Finding | Reason |
|---|---|---|
| classes/local/backfill_status.php `get_message()` | cyclomatic 10 (= threshold) | one `switch` over the six documented backfill statuses plus the "interrupted" check; splitting it would scatter the status table; the statuses are covered by backfill_task_test::test_pending_states and docs_test::test_every_backfill_status_has_a_message, the messages by Behat and Playwright |
| classes/local/config_repository.php `upsert()` | BooleanArgumentFlag `$assumenew` | test seam to exercise the lost-race path of the unique key deterministically (config_repository_test); production callers never pass it |
