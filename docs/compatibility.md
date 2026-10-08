Compatibility
=============

The plugin uses these enrol_lti APIs and fields. They were compared on 2026-10-08 in the
current branches MOODLE_405_STABLE (4.5.15), MOODLE_500_STABLE (5.0.11), MOODLE_501_STABLE
(5.1.8, public/ layout), MOODLE_502_STABLE (5.2.4) and MOODLE_503_STABLE (5.3, build 20261005):

| API / field | 4.5 | 5.0 | 5.1 | 5.2 | 5.3 |
|---|---|---|---|---|---|
| `\enrol_lti\helper::get_lti_tools()` (signature and SQL) | identical | identical | identical | identical | identical |
| `\enrol_lti\helper::get_lti_tool()` | identical | identical | identical | identical | identical |
| `\enrol_lti\helper::get_name()` | identical | identical | identical | identical | identical |
| `enrol_lti_tools`: id, enrolid, contextid, ltiversion | present | present | present | present | present |

"identical" means the function source is byte-identical to 4.5. get_lti_tools() returns the
enrol_lti_tools records joined with enrol.name, courseid, status, enrolstartdate, enrolenddate and
enrolperiod, keyed by tool id and ordered by timecreated; the plugin relies on exactly these
fields.

The CI matrix runs the PHPUnit, Behat and upgrade tests on all five versions (see
.github/workflows/moodle-plugin-ci.yml); the Playwright browser tests run on Moodle 4.5 and
5.3 (.github/workflows/playwright.yml). Moodle 5.3 requires PHP 8.3 or later, PostgreSQL 17 or later and
MariaDB 11.4 or later; the CI uses exactly these minimums for the 5.3 cells.
