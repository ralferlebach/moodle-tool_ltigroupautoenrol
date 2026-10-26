<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Installation smoke test of the installed plugin ZIP (CI job install-smoke).
 *
 * Runs against a fresh Moodle site on which exactly the release ZIP was installed: checks the installed
 * version against the ZIP's version.php, then exercises the production path once: an LTI 1.3 enrolment
 * through enrol_lti assigns the mapped group (event observer), the backfill preview counts a participant
 * enrolled before the configuration, the backfill task assigns them, and course deletion removes the
 * configuration. Every failure, including exceptions and missing classes, ends in a report line and
 * exit code 1 instead of a crash.
 *
 * Usage: php install_smoke.php <moodle root> <directory of the installed plugin>
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The config.php of the site under test is included from the path given on the command line.
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
define('CLI_SCRIPT', true);

$root = $argv[1] ?? '';
$plugindir = $argv[2] ?? '';
if (!is_file($root . '/config.php') || !is_file($plugindir . '/version.php')) {
    fwrite(STDERR, "Usage: php install_smoke.php <moodle root> <directory of the installed plugin>\n");
    exit(2);
}
require($root . '/config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

$failures = [];
$check = function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures[] = $label;
    }
};

try {
    $plugin = new stdClass();
    require($plugindir . '/version.php');
    $installed = get_config('tool_ltigroupautoenrol', 'version');
    $check((string) $installed === (string) $plugin->version, "installed version {$installed} = ZIP version {$plugin->version}");

    $CFG->noemailever = true;
    \core\plugininfo\enrol::enable_plugin('lti', 1);
    $gen = new testing_data_generator();
    $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
    $course = $gen->create_course(['shortname' => 'SMOKE' . random_string(8)]);
    $group = $gen->create_group(['courseid' => $course->id, 'name' => 'Consumer A']);
    $tool = $plugingen->create_lti_tool($course->id, ['name' => 'Smoke tool']);

    $before = $gen->create_user();
    $plugingen->enrol_via_lti($tool, $before->id);
    $config = $plugingen->create_config($course->id, [$tool->id => [$group->id]]);

    $after = $gen->create_user();
    $plugingen->enrol_via_lti($tool, $after->id);
    $check(groups_is_member($group->id, $after->id), 'new LTI enrolment assigned to the mapped group (observer)');
    $check(!groups_is_member($group->id, $before->id), 'participant enrolled before the configuration not yet assigned');

    $check(
        \tool_ltigroupautoenrol\local\assignment_service::count_missing($config) === 1,
        'backfill preview counts one missing membership'
    );
    \tool_ltigroupautoenrol\task\backfill_task::create($course->id, $config->mapping->get_hash(), time())->execute();
    $check(groups_is_member($group->id, $before->id), 'backfill task assigned the earlier participant');
    $last = \tool_ltigroupautoenrol\local\config_repository::get_last_backfill($course->id);
    $check(
        $last !== null && $last->status === \tool_ltigroupautoenrol\local\config_repository::BACKFILL_DONE,
        'backfill status done'
    );

    delete_course($course, false);
    $check(\tool_ltigroupautoenrol\local\config_repository::get($course->id) === null, 'course deletion removed the configuration');
} catch (\Throwable $e) {
    $check(false, 'no exception: ' . get_class($e) . ': ' . $e->getMessage());
}

echo $failures ? 'INSTALLATION SMOKE TEST FAILED: ' . count($failures) . " check(s)\n" : "INSTALLATION SMOKE TEST OK\n";
exit($failures ? 1 : 0);
