# Changelog

## 1.2 (2026100800)

Security and integrity
- New capability `tool/ltigroupautoenrol:manage` for navigation, settings page and backfill (#1).
- Server-side validation of the submitted group ids against the current course; the form only contains selects for the course's own LTI tools and no hidden tool ids or counters anymore (#1).
- One access contract for navigation link and both pages, GET and POST; the site course is excluded (#1, #6).
- At most one configuration per course (unique key), deterministic handling of concurrent saves (#3).
- Stored mappings are validated; corrupt mappings are ignored at runtime and reported (#3, #9).

Features
- Assign existing LTI participants (backfill) with read-only preview, confirmation and background processing. Only the participants of the preview are assigned; a configuration changed in between requires a new confirmation. The status of the last run (scheduled, running, done with counters, not carried out) is shown on the settings and preview pages (#2).
- Configuration is cleaned up when courses, LTI tools or groups are deleted (#8).
- Group selection is kept when the feature is disabled (previously it was lost on save).
- Empty states for courses without groups or LTI 1.3 tools, help texts (#6).

Architecture and tests
- Shared assignment service for event and backfill with result counters (#9).
- End-to-end PHPUnit and Behat tests with real enrol_lti instances and enrolments (#5).
- Upgrade tests from the original schemas of 0.1, the published 1.0.1 and 1.1 (#4).
- Uninstall/reinstall test, query budget tests, tests for missing records and partial failures (#5, #8, #9).
- Playwright browser tests with axe, keyboard/focus, reflow at 200 %/400 % zoom, phone and German UI (#6, #7).
- Failed group assignments of new enrolments are reported in the debugging output (#9).
- Strings whose meaning changed got new identifiers, so outdated community translations are not shown.
- Privacy tests based on the actual data flows (#8).
- CI: Moodle 5.2 tested explicitly, hard vs. advisory gates, release ZIP with SHA-256 evidence (#7).

Upgrade
- Repairs installations upgraded from 0.1, which lacked the `settings` field (#4).
- Removes the unreachable upgrade step inherited from tool_groupautoenrol (#4).
- Backs up every removed value and row to moodledata/tool_ltigroupautoenrol/upgrade-*.json (#3, #4).
- Read-only pre-upgrade check: cli/check_upgrade.php (#3).

## 1.1 (2026030700)

- Moodle 4.5 – 5.2, CI, code checks, unit tests.

## 1.0.1 (2024100600)

- Per-tool group mapping for LTI 1.3.
