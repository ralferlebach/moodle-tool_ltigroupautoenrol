# Changes and upgrade notes

## Upgrading

Supported upgrade baselines: 0.1 (2024050100 - 2024090802), the published 1.0 (2024093000) and 1.0.1 (2024100600) and all versions up to 1.1 (2026030700). The original database schema of each baseline is kept in tests/fixtures (checked against the ZIPs published in the Moodle plugins directory); the PHPUnit tests upgrade from each of them, and the CI runs them on PostgreSQL and MariaDB. In addition, the CI job `upgrade-e2e` upgrades a real site from the published 1.0.1 exactly as described below.

The upgrade to 1.2 (2026100800, 2026100801)

* adds the field "settings" to installations that were upgraded from 0.1 and removes the unused tool_groupautoenrol fields of 0.1 (0.1 never evaluated them),
* removes configurations of deleted courses and duplicate configurations of a course (the oldest row is kept, because it was the one in effect),
* adds a unique key on the course and the fields for the backfill status,
* rewrites stored mappings into a canonical format; invalid mappings are kept and ignored at runtime,
* (2026100801) adds the backfill status fields to sites that installed the pre-release state of 2026100800 from pull request #11; on all other sites this step changes nothing.

Nothing is removed without a backup: every removed value and row is written as JSON to moodledata/tool_ltigroupautoenrol/upgrade-*.json, and the upgrade output names the files.

Recommended procedure:

1. Take a database backup and a backup of moodledata.
2. Put the new plugin code in place and run the read-only check before the upgrade:
   `php admin/tool/ltigroupautoenrol/cli/check_upgrade.php` (Moodle 4.5 and 5.0) or
   `php public/admin/tool/ltigroupautoenrol/cli/check_upgrade.php` (Moodle 5.1 and later)
   It lists legacy fields, configurations of deleted courses, duplicate configurations per course and invalid mappings - everything the upgrade will back up, remove or keep - and changes nothing.
3. Run the upgrade and keep its output.

Rollback: Moodle does not support downgrading a plugin. Restore the database backup together with the previous plugin code. Single removed rows can also be restored from the JSON backup files.

# Changelog

## 1.2 (2026100900)

- Playwright CI (Moodle 5.3): the backfill test ran all adhoc tasks of the freshly installed site and exceeded its time limit on GitHub; it now runs only the plugin's backfill task. tests/playwright/reset_backfill.php restores the seeded backfill course before every attempt, so a retry no longer depends on the first attempt.

## 1.2 (2026100802)

- Fix: the read-only pre-upgrade check `cli/check_upgrade.php` failed with "Class tool_ltigroupautoenrol\local\mapping not found" when run as documented, i.e. with the new code in place but before the upgrade (Moodle's class cache still describes the installed version, which had no classes/local). db/upgradelib.php now loads its classes explicitly; the upgrade itself is covered the same way.
- Real site upgrade test (tests/upgrade/upgrade_e2e.sh, CI job `upgrade-e2e`): installs the published 1.0.1, stores a duplicate and an orphaned configuration, runs the read-only check, upgrades and requires "Database structure is ok.", one row per course with the kept mapping, the unique key and a backup of every removed row. Runs on Moodle 4.5 and 5.3 with PostgreSQL and MariaDB.
- The published ZIPs of 1.0 (2024093000), 1.0.1 (2024100600) and 1.1 (2026030700) were downloaded from the Moodle plugins directory (MD5 as published) and compared: their install.xml files are identical to tests/fixtures (1.0 has the 1.0.1 schema), the 1.0.1 code is identical to git fd456f3, 1.1 differs from git 104b1c3 only in README.md.

## 1.2 (2026100801)

- Support for Moodle 5.3 (`$plugin->supported = [405, 503]`). The enrol_lti APIs the plugin uses are unchanged in 5.3 (docs/compatibility.md).
- CI: Moodle 5.3 replaces the experimental `main` cell as a hard gate (PHP 8.3 and 8.4, PostgreSQL 17, MariaDB 11.4); database images per branch; MariaDB health check that also works with MariaDB 11 images (no `mysqladmin` there anymore).
- Tests: the upgrade tests parse the historical schemas with `\core\xml_parser` where available (xmlize() is deprecated since Moodle 5.1 and no longer loaded implicitly); the Playwright seed script runs the CLI scripts from the Moodle root, which is no longer the web root since 5.1. Both failed on Moodle 5.1 and later.
- Playwright browser tests now also run on Moodle 5.3 (public/ layout, PostgreSQL 17); the workflow installs Moodle's Composer dependencies, which Moodle 5.1 and later need at runtime.
- Upgrade step 2026100801: repairs sites that installed the pre-release state of 2026100800 (missing backfill status fields), with an upgrade test against that schema (tests/fixtures/install_2026100800_pr11.xml).

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
- Failed group assignments of new enrolments are counted per course and shown as a warning on the settings page until a complete backfill, and written to the server log (#9).
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
