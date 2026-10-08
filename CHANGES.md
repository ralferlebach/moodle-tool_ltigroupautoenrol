# Changelog

## 1.2 (2026100800)

Security and integrity
- New capability `tool/ltigroupautoenrol:manage` for navigation, settings page and backfill (#1).
- Server-side validation of submitted tool and group ids against the current course; the form no longer contains hidden tool ids or counters (#1).
- At most one configuration per course (unique key), deterministic handling of concurrent saves (#3).
- Stored mappings are validated; corrupt mappings are ignored at runtime and reported (#3, #9).

Features
- Assign existing LTI participants (backfill) with read-only preview, confirmation and background processing (#2).
- Configuration is cleaned up when courses, LTI tools or groups are deleted (#8).
- Group selection is kept when the feature is disabled (previously it was lost on save).
- Empty states for courses without groups or LTI 1.3 tools, help texts (#6).

Architecture and tests
- Shared assignment service for event and backfill with result counters (#9).
- End-to-end PHPUnit and Behat tests with real enrol_lti instances and enrolments (#5).
- Upgrade-path tests against the 0.1 and 1.1 schemas (#4).
- Privacy tests based on the actual data flows (#8).
- CI: Moodle 5.2 tested explicitly, hard vs. advisory gates, release ZIP with SHA-256 evidence (#7).

Upgrade
- Repairs installations upgraded from 0.1, which lacked the `settings` field (#4).
- Removes the unreachable upgrade step inherited from tool_groupautoenrol (#4).

## 1.1 (2026030700)

- Moodle 4.5 – 5.2, CI, code checks, unit tests.

## 1.0.1 (2024100600)

- Per-tool group mapping for LTI 1.3.
