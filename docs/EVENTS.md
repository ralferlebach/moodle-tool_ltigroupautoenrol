Events of tool_ltigroupautoenrol (since 1.2.1)
===============================================

Audit item GA-03. The plugin reports every group assignment it performs as a Moodle event, so other plugins can
correlate assignments and their failures **read-only** from the log (for example tool_ltidiagnostics, which reads
`logstore_standard_log` by the component prefix `tool_ltigroupautoenrol`). The plugin does not depend on any reader:
it has no dependency in version.php, no reference to another family plugin in its code (checked by
`group_assignment_events_test::test_no_dependency_on_family_plugins`) and works the same with or without a log
store or a diagnostics plugin. Reporting never changes the outcome of an assignment: a failure to trigger an event
is swallowed (developer debugging with the class name only).

Contract (agreed with the tool_ltidiagnostics maintainers on 2026-10-27)
--------------------------------------------------------------------------

Both events:

| Field | Value |
|---|---|
| component | `tool_ltigroupautoenrol` |
| crud / edulevel | `c` / `LEVEL_OTHER` |
| contextid / contextlevel | the course context (`CONTEXT_COURSE`) |
| courseid | the course |
| objecttable / objectid | `enrol` / id of the enrol_lti instance of the tool (restore mapping `enrol`) |
| relateduserid | the LTI-enrolled user; may be empty only in `group_assignment_failed` for an aborted backfill run |
| userid | core semantics: the user logged in when the event was triggered (may be 0, e.g. before the login of an LTI launch completed, or the cron user for the backfill); use `relateduserid` for the participant |

`other` is validated by `validate_data()`: exactly the keys below, ints are non-negative, any other key is rejected
with a `coding_exception`. So names, e-mail addresses, group names, LTI `sub`/issuer values or messages can not be
logged by this plugin. Only ids, counters and machine codes are stored.

### `\tool_ltigroupautoenrol\event\group_assignment_completed`

The groups mapped to an LTI tool were processed for one user.

| other key | type | meaning |
|---|---|---|
| `source` | string | `event` (new LTI enrolment, enrolment observer) or `backfill` ("Assign existing LTI participants") |
| `added` | int | memberships added |
| `existing` | int | memberships that already existed |
| `errors` | int | memberships that could not be added (then a `group_assignment_failed` follows) |
| `toolid` | int | `enrol_lti_tools.id` (not mapped on restore) |

When it is triggered:

* `source=event`: for every **eligible** new LTI 1.3 enrolment of a tool that is mapped to at least one group in a
  course with the feature enabled. Nothing is triggered for unmapped tools, other enrolment methods, LTI 1.1 tools, a
  disabled feature, or not eligible enrolments (suspended, not yet started, expired, deleted user).
* `source=backfill`: once per user and tool **for which a membership was added or failed**. Users that already are
  members of all mapped groups are not logged, so a repeated backfill of a large course writes no log rows.

### `\tool_ltigroupautoenrol\event\group_assignment_failed`

| other key | type | meaning |
|---|---|---|
| `source` | string | `event` or `backfill` |
| `code` | string | machine code (`[A-Za-z0-9_]{1,100}`), see below |
| `errors` | int | memberships that could not be added; `1` when the number is unknown (exception) |
| `toolid` | int | `enrol_lti_tools.id`; `0` if the failure happened before the tool was resolved |

| code | situation | relateduserid |
|---|---|---|
| `add_member_failed` | `groups_add_member()` refused the membership (e.g. user no longer enrolled) | user |
| `tool_course_mismatch` | invariant violation: the tool does not belong to the configured course; nothing added | user |
| exception class basename, e.g. `dml_write_exception` | `groups_add_member()` threw a `moodle_exception` for this user | user |
| exception class basename, e.g. `TypeError`, `dml_read_exception` | the enrolment observer failed (error boundary; enrolment kept, failure counted for the course, `toolid` 0) | user |
| exception class basename | a backfill run of a tool was aborted; the task API retries it | empty |

Correlation hints for readers
-----------------------------

* An LTI 1.3 launch on the tool site creates the enrolment (`\core\event\user_enrolment_created`, objectid =
  `user_enrolments.id`, `other.enrol = lti`) and, in the same request, `group_assignment_completed` with the same
  `courseid` and `relateduserid`, `objectid` = `user_enrolments.enrolid`. Core's `\core\event\group_member_added`
  (one per added membership, objectid = group id) follows in the same request.
* `toolid` joins `enrol_lti_tools.id`; `objectid` joins `enrol.id` (`enrol_lti_tools.enrolid`).
* Cost for a reader: an observer of these events runs once per event, i.e. once per eligible new LTI enrolment and
  once per changed user in a backfill (tool_ltidiagnostics adds one insert per event; measured in the PHPUnit
  budget of the event path, which therefore counts only this plugin's own observers).
* A course with unresolved failures of the event path also shows a warning on the plugin's settings page until a
  complete backfill run; the counters of the last backfill are on the backfill page.

Privacy
-------

The events are stored, exported and deleted by the log stores (Privacy API of `logstore_standard` by
`relateduserid`/`userid`). The plugin itself still stores no personal data (null provider, reason string
`privacy:reason_events`).

Evidence
--------

`tests/event/group_assignment_events_test.php` (payload, names, descriptions, validation, standard log store,
backfill only for changes, aborted backfill, independence), `tests/observer_failure_test.php` (error boundary of the
real enrolment path reports `group_assignment_failed`), Playwright `tests/playwright/e2e/ltigroupautoenrol_e2e.spec.js`
(events of real LTI launches in the log of the tool site). Results: docs/ACCEPTANCE.md.
