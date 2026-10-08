# Moodle-admin_tool_ltigroupautoenrol

Version 1.2 (stable) for Moodle 4.5 to 5.2

Plugin to automatically enrol users to pre-defined group(s) when they are enrolled in a course that is shared via LTI 1.3. Moodle administrators and teachers who act as LTI providers maintain only one course but can still separate the users coming from different consumers (LTI tools / published resources).

This plugin is derived from the tool_groupautoenrol plugin.

## Features

- Enable the feature per course.
- Map every LTI 1.3 tool (published resource) of the course to one or more groups.
- New LTI enrolments are assigned to the mapped groups automatically.
- Participants who were enrolled before the mapping was saved can be assigned afterwards ("Assign existing LTI participants"): read-only preview per tool, explicit confirmation, processed in the background by an adhoc task.
- Assignment is strictly additive: users are never removed from groups, not even when the feature is disabled or the plugin is uninstalled.
- Only LTI 1.3 (LTI Advantage) tools are supported; LTI 1.1 tools are ignored.

## Usage

1. Create at least one group in the course.
2. Publish the course or an activity as LTI 1.3 tool (*Course administration > Users > Enrolment methods > Publish as LTI tool*).
3. Open *Course administration > Users > LTI-enrol in groups*, enable the feature and select the groups per tool.
4. Optionally: *Assign existing LTI participants …*, check the preview, confirm. The assignment runs with the next cron run.

## Permissions

| Capability | Default | Purpose |
|---|---|---|
| `tool/ltigroupautoenrol:manage` | editing teacher, manager (cloned from `moodle/course:managegroups` on upgrade) | Navigation link, settings page (GET and POST) and backfill |

The navigation link and the pages use the same capability. Submitted tool and group ids are validated on the server against the LTI tools and groups of the current course; foreign or manipulated ids are rejected.

## Assignment rules

A user enrolment is assigned only if

- it belongs to the `enrol_lti` instance of a mapped LTI 1.3 tool of the same course,
- the enrolment instance is enabled and the user enrolment is active (not suspended),
- the current time lies within the enrolment's start and end date,
- the user is not deleted.

A user enrolled through several tools is assigned to the groups of each tool. Mapped groups that no longer exist are skipped. Enrolments that become active later (start date in the future, reactivated suspension) are not assigned by the event; run the backfill for them.

## Privacy and lifecycle

| Data | Stored where | Personal data |
|---|---|---|
| Course settings (enabled flag, tool id → group ids) | `tool_ltigroupautoenrol` (one row per course) | no |
| Group memberships added by the plugin | Moodle core (`groups_members`, component empty) | yes, exported and deleted by `core_group` |
| Backfill task | adhoc task custom data: course id and mapping fingerprint | no |
| Logs / debugging | course ids and counters only | no |

The plugin therefore implements the `null_provider`; the tests in `tests/privacy_provider_test.php` check these data flows.

| Event | Behaviour |
|---|---|
| Course deleted | The plugin configuration of the course is deleted. |
| LTI enrol instance deleted | The tool is removed from the mapping. Memberships stay. |
| Group deleted | The group is removed from the mapping. Memberships are removed by core. |
| Feature disabled | Mapping is kept, nothing is assigned anymore. Memberships stay. |
| Plugin uninstalled | The plugin table is dropped. Memberships stay. |

Memberships are created with an empty component, so teachers can still remove them manually.

## Upgrade

Supported upgrade baselines: 0.1 (2024050100–2024090802), 0.2–1.1 (2024092701–2026030700). The upgrade to 1.2 (2026100800)

- repairs the 0.1 schema (adds the missing `settings` field, reports and drops the unused tool_groupautoenrol fields; 0.1 never evaluated them),
- removes configuration rows of deleted courses and duplicate rows per course (keeps the oldest row, reports all removed rows in the upgrade output),
- adds a unique key on `courseid`,
- rewrites stored mappings into the canonical format; corrupt mappings are reported and ignored at runtime.

Take a database backup before upgrading. To roll back, restore the backup together with the previous plugin code; a downgrade of the plugin version is not supported by Moodle.

## Quality gates

The GitHub workflow tests Moodle 4.5, 5.0, 5.1 and 5.2 with PostgreSQL and MariaDB on the supported PHP versions. Hard gates: phplint, codechecker, phpdoc, validate, savepoints, mustache, grunt, PHPUnit (including upgrade-path tests against the historical schemas) and Behat (including axe accessibility checks). phpcpd/phpmd and Moodle `main` are advisory. The `release-package` job builds the ZIP with `git archive`, checks its contents and publishes the commit, CI run and SHA-256 as release evidence.

## Installation

* Copy the directory `ltigroupautoenrol` into the `moodledir/admin/tool` directory.
* Connect to Moodle as an administrator and install the plugin.

# License
* @copyright  2026 Ralf Erlebach
* @author     Ralf Erlebach - https://github.com/ralferlebach

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not, see https://www.gnu.org/licenses/.
