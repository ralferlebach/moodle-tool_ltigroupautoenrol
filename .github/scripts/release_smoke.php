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
 * Smoke test of the installed release ZIP (CI job release-smoke).
 *
 * Runs against a Moodle site on which exactly the release ZIP was installed: checks the installed
 * version against the ZIP's version.php, then exercises the production path once: an LTI 1.3
 * enrolment through enrol_lti assigns the mapped group (event observer), the backfill preview counts
 * a participant enrolled before the configuration and the backfill task assigns them.
 *
 * Usage: php release_smoke.php <moodle root> <plugin directory of the installed ZIP>
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\task\backfill_task;

define('CLI_SCRIPT', true);

$root = $argv[1] ?? '';
$plugindir = $argv[2] ?? '';
require($root . '/config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

$failures = [];
$check = function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $failures[] = $label;
    }
};

$plugin = new stdClass();
require($plugindir . '/version.php');
$installed = get_config('tool_ltigroupautoenrol', 'version');
$check((string) $installed === (string) $plugin->version, "installed version {$installed} = ZIP version {$plugin->version}");

$CFG->noemailever = true;
\core\plugininfo\enrol::enable_plugin('lti', 1);
$gen = new testing_data_generator();
$plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
$course = $gen->create_course(['shortname' => 'SMOKE' . random_string(6)]);
$group = $gen->create_group(['courseid' => $course->id, 'name' => 'Consumer A']);
$tool = $plugingen->create_lti_tool($course->id, ['name' => 'Smoke tool']);

$before = $gen->create_user();
$plugingen->enrol_via_lti($tool, $before->id);
$config = $plugingen->create_config($course->id, [$tool->id => [$group->id]]);

$after = $gen->create_user();
$plugingen->enrol_via_lti($tool, $after->id);
$check(groups_is_member($group->id, $after->id), 'new LTI enrolment assigned to the mapped group (observer)');
$check(!groups_is_member($group->id, $before->id), 'participant enrolled before the configuration not yet assigned');

$check(assignment_service::count_missing($config) === 1, 'backfill preview counts one missing membership');
ob_start();
backfill_task::create($course->id, $config->mapping->get_hash(), time())->execute();
ob_end_clean();
$check(groups_is_member($group->id, $before->id), 'backfill task assigned the earlier participant');
$check(config_repository::get_last_backfill($course->id)->status === config_repository::BACKFILL_DONE, 'backfill status done');

delete_course($course, false);
$check(config_repository::get($course->id) === null, 'course deletion removed the configuration');

echo $failures ? 'RELEASE SMOKE FAILED: ' . implode('; ', $failures) . "\n" : "RELEASE SMOKE OK\n";
exit($failures ? 1 : 0);
