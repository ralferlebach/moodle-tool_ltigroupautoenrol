moodle-tool_ltigroupautoenrol
=============================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-tool_ltigroupautoenrol/actions/workflows/moodle-plugin-ci.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-tool_ltigroupautoenrol/actions?query=workflow%3A%22Moodle+Plugin+CI%22+branch%3Amain)

Moodle admin tool plugin which automatically adds users to pre-defined groups when they are enrolled in a course through an LTI 1.3 tool. The groups are chosen per LTI tool, so one course can serve several LTI consumers and still keep their users apart.


Requirements
------------

This plugin requires Moodle 4.5+ and is supported up to Moodle 5.2.

It works with LTI 1.3 (LTI Advantage) tools published by Moodle's "Publish as LTI tool" enrolment method (enrol_lti). LTI 1.1 tools are ignored.


Motivation for this plugin
--------------------------

Teachers and administrators who act as LTI providers often share one course with several consumers, for example different universities or schools. Without this plugin, all users coming from all consumers end up in the same, undivided course. With this plugin, the course is maintained only once, while the users of every consumer are separated into their own groups automatically.

This plugin is derived from tool_groupautoenrol by Pascal M.


Installation
------------

Install the plugin like any other plugin to folder
/admin/tool/ltigroupautoenrol

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins


Usage & Settings
----------------

After installing the plugin, it does not do anything to Moodle yet. It is configured per course; there are no site-wide settings.

To configure the plugin for a course:

1. Create at least one group in the course.
2. Publish the course or an activity as LTI 1.3 tool: Course administration -> Users -> Enrolment methods -> Publish as LTI tool.
3. Open Course administration -> Users -> LTI-enrol in groups.

There, you find these settings:

* **Enable automatic enrolment in groups for this course** - Switches the automatic group assignment for this course on or off. Switching it off keeps the selected groups.
* **Groups for LTI tool: [tool]** - One multi-select per LTI 1.3 tool of the course. Users enrolled through this tool are added to the selected groups.

Participants who were enrolled before the configuration was saved can be assigned afterwards with **Assign existing LTI participants …**. The page shows a preview per LTI tool (active LTI enrolments and missing group memberships) without changing anything. After confirmation, the assignment is processed in the background by the next cron run. Only the participants of the preview are assigned: enrolments created after the preview are left to the automatic assignment of new enrolments. If the configuration is changed or disabled between preview and processing, the run is not carried out and has to be confirmed again.

The settings page and the preview page show the status of the last run: scheduled, running, done (with the numbers added, already member, skipped, errors) or not carried out.

If you want to learn more about using admin tool plugins in Moodle, please see https://docs.moodle.org/en/Admin_tools.


Capabilities
------------

This plugin also introduces these additional capabilities:

* **tool/ltigroupautoenrol:manage** - Allows to configure the automatic group assignment of a course and to assign existing LTI participants. The navigation link, the settings page and the backfill page all require this capability. By default, it is assigned to the editing teacher and manager roles; on upgrade, it is cloned from moodle/course:managegroups.


Scheduled Tasks
---------------

This plugin does not add any additional scheduled tasks.

It uses one adhoc task, **\tool_ltigroupautoenrol\task\backfill_task**, which is queued when a user confirms "Assign existing LTI participants" and is processed by the next cron run.


How this plugin works / Pitfalls
--------------------------------

The plugin observes Moodle's user_enrolment_created event. A user enrolment is assigned to the groups of its LTI tool only if

* it belongs to the enrol_lti instance of an LTI 1.3 tool of the same course,
* the enrolment method instance is enabled and the user enrolment is active (not suspended),
* the current time lies within the enrolment's start and end date,
* the user is not deleted.

A user enrolled through several LTI tools is assigned to the groups of each tool. Groups which no longer exist are skipped.

Pitfalls:

* **Group assignment is strictly additive.** Users are never removed from groups - neither when the feature is disabled nor when the plugin is uninstalled. Group memberships created by the plugin can still be removed manually by teachers.
* **Only new enrolments are handled by the event.** Users who were enrolled before the configuration was saved, or whose enrolment becomes active later (start date in the future, suspension lifted), have to be assigned with "Assign existing LTI participants".
* **Lifecycle:** When a course is deleted, its configuration is deleted. When an LTI enrolment method instance or a group is deleted, it is removed from the configuration. Existing group memberships are not touched by the plugin.
* **Errors never block an enrolment.** If a group membership can not be added for a new enrolment, the enrolment itself succeeds; the failure is reported in Moodle's debugging output (course and number only). The background assignment records failures in its status and is retried by the task API.
* **Language packs:** Community translations from AMOS override the plugin's own language files. Strings whose meaning changed in 1.2 therefore got new identifiers, so outdated translations of 1.0.1/1.1 are not shown.


Upgrading
---------

Supported upgrade baselines: 0.1 (2024050100 - 2024090802), the published 1.0.1 (2024100600) and all versions up to 1.1 (2026030700). The original database schema of each baseline is kept in tests/fixtures; the PHPUnit tests upgrade from each of them, and the CI runs them on PostgreSQL and MariaDB.

The upgrade to 1.2 (2026100800)

* adds the field "settings" to installations that were upgraded from 0.1 and removes the unused tool_groupautoenrol fields of 0.1 (0.1 never evaluated them),
* removes configurations of deleted courses and duplicate configurations of a course (the oldest row is kept, because it was the one in effect),
* adds a unique key on the course and the fields for the backfill status,
* rewrites stored mappings into a canonical format; invalid mappings are kept and ignored at runtime.

Nothing is removed without a backup: every removed value and row is written as JSON to moodledata/tool_ltigroupautoenrol/upgrade-*.json, and the upgrade output names the files.

Recommended procedure:

1. Take a database backup and a backup of moodledata.
2. Put the new plugin code in place and run the read-only check before the upgrade:
   `php admin/tool/ltigroupautoenrol/cli/check_upgrade.php`
   It lists legacy fields, configurations of deleted courses, duplicate configurations per course and invalid mappings - everything the upgrade will back up, remove or keep - and changes nothing.
3. Run the upgrade and keep its output.

Rollback: Moodle does not support downgrading a plugin. Restore the database backup together with the previous plugin code. Single removed rows can also be restored from the JSON backup files.


Data privacy
------------

The plugin implements Moodle's null privacy provider. Data flows:

* **Plugin table:** course settings (which LTI tool is mapped to which groups) and status, time and counters of the last "Assign existing LTI participants" run. No user data.
* **Group memberships:** stored in Moodle core through the groups API; exported and deleted by Moodle's groups subsystem (core_group).
* **Events and logs:** the plugin triggers no events of its own. Adding a membership triggers core's group_member_added event, which the standard log store records; it is covered by the log store's privacy provider.
* **Background task:** course id, configuration fingerprint and preview time only.
* **Debugging output and upgrade backups:** course ids, counters and course settings only.


Theme support
-------------

This plugin is developed and tested on Moodle Core's Boost theme.
It should also work with Boost child themes, including Moodle Core's Classic theme. However, we can't support any other theme than Boost.


Plugin repositories
-------------------

This plugin is published and regularly updated in the Moodle plugins repository:
http://moodle.org/plugins/view/tool_ltigroupautoenrol

The latest development version can be found on Github:
https://github.com/ralferlebach/moodle-tool_ltigroupautoenrol


Bug and problem reports / Support requests
------------------------------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-tool_ltigroupautoenrol/issues

We will do our best to solve your problems, but please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Due to limited resources, the functionality of this plugin is primarily implemented for our own local needs and published as-is to the community. We are aware that members of the community will have other needs and would love to see them solved by this plugin.

Please issue feature proposals on Github:
https://github.com/ralferlebach/moodle-tool_ltigroupautoenrol/issues

Please create pull requests on Github:
https://github.com/ralferlebach/moodle-tool_ltigroupautoenrol/pulls

We are always interested to read about your feature proposals or even get a pull request from you, but please accept that we can handle your issues only as feature _proposals_ and not as feature _requests_.


Moodle release support
----------------------

Due to limited resources, this plugin is only maintained for the most recent major release of Moodle as well as the most recent LTS release of Moodle. Bugfixes are backported to the LTS release. However, new features and improvements are not necessarily backported to the LTS release.

Apart from these maintained releases, previous versions of this plugin which work in legacy major releases of Moodle are still available as-is without any further updates in the Moodle Plugins repository.

There may be several weeks after a new major release of Moodle has been published until we can do a compatibility check and fix problems if necessary. If you encounter problems with a new major release of Moodle - or can confirm that this plugin still works with a new major release - please let us know on Github.

This plugin is designed to be compatible with all currently supported versions of Moodle, leveraging its latest APIs. However, if you are using a legacy version of Moodle, we kindly advise against installing or using this plugin. Instead, we strongly recommend updating your Moodle instance to a supported version to ensure security and compliance with current technological standards. Thank you for your understanding.

The continuous integration tests these combinations: Moodle 4.5 with PHP 8.1 and 8.3, Moodle 5.0 and 5.1 with PHP 8.4, Moodle 5.2 with PHP 8.3 and 8.4, each with PostgreSQL and MariaDB (Moodle 4.5/PHP 8.1 with PostgreSQL only). As long as Moodle 4.5 is supported, the PHPUnit tests keep the PHPUnit 9 conventions (@covers and @dataProvider annotations in docblocks).

Tests: PHPUnit (incl. upgrade tests from the historical schemas, uninstall/reinstall and query budgets), Behat (incl. axe accessibility checks) and Playwright browser tests (tests/playwright: page identity, axe, keyboard and focus, reflow at 200 %/400 % zoom and on a phone, German user interface, access denial).


Translating this plugin
-----------------------

This Moodle plugin is provided with English and German language packs only. Translations into other languages must be managed through AMOS (https://lang.moodle.org), where they will become part of Moodle's official language pack.

As the plugin creator, we continue to maintain the German translation. For all other languages, we kindly ask you to contribute your translations directly in AMOS. These contributions will be reviewed by Moodle's official language pack maintainers before being included in the official repository.

Thank you for supporting the global Moodle community!


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.
If you want to use this plugin with a RTL language and it doesn't work as-is, you are free to send us a pull request on Github with modifications.


Maintainers
-----------

The plugin is maintained by\
Ralf Erlebach


Copyright
---------

The copyright of this plugin is held by\
Ralf Erlebach

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
