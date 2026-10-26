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
 * CLI seed for the tool_ltigroupautoenrol Playwright tests.
 *
 * Creates, with unique names on every run:
 * - an editing teacher and a non-editing teacher (password LTIGAE_PASSWORD),
 * - "LTI course": groups "Consumer A"/"Consumer B", LTI 1.3 tools "Tool Alpha"/"Tool Beta", not configured,
 * - "Backfill course": tool "Tool Alpha" mapped to "Consumer A" (enabled) and one LTI participant
 *   enrolled before the configuration, i.e. not yet assigned,
 * - "No groups course" and "No tools course" for the empty states.
 * Prints the ids as shell "export" lines.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

// The test site sends no mail.
$CFG->noemailever = true;
$password = 'Teacher!23';
$suffix = substr(uniqid(), -6);
$gen = new testing_data_generator();
$plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
\core\plugininfo\enrol::enable_plugin('lti', 1);

$teacher = $gen->create_user(['username' => 'ltigaeteacher' . $suffix, 'password' => $password,
    'firstname' => 'Tessa', 'lastname' => 'Teacher']);
$assistant = $gen->create_user(['username' => 'ltigaeassistant' . $suffix, 'password' => $password,
    'firstname' => 'Arne', 'lastname' => 'Assistant']);

$newcourse = function (string $name) use ($gen, $suffix, $teacher, $assistant) {
    $course = $gen->create_course(['fullname' => $name, 'shortname' => $name . ' ' . $suffix]);
    $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
    $gen->enrol_user($assistant->id, $course->id, 'teacher');
    return $course;
};

$lticourse = $newcourse('LTI course');
$gen->create_group(['courseid' => $lticourse->id, 'name' => 'Consumer A']);
$gen->create_group(['courseid' => $lticourse->id, 'name' => 'Consumer B']);
$plugingen->create_lti_tool($lticourse->id, ['name' => 'Tool Alpha']);
$plugingen->create_lti_tool($lticourse->id, ['name' => 'Tool Beta']);

$backfillcourse = $newcourse('Backfill course');
$group = $gen->create_group(['courseid' => $backfillcourse->id, 'name' => 'Consumer A'])->id;
$tool = $plugingen->create_lti_tool($backfillcourse->id, ['name' => 'Tool Alpha']);
$plugingen->enrol_via_lti($tool, $gen->create_user(['firstname' => 'Lea', 'lastname' => 'Learner'])->id);
$plugingen->create_config($backfillcourse->id, [$tool->id => [$group]]);

$nogroups = $newcourse('No groups course');
$plugingen->create_lti_tool($nogroups->id, ['name' => 'Tool Alpha']);

$notools = $newcourse('No tools course');
$gen->create_group(['courseid' => $notools->id, 'name' => 'Consumer A']);

$exports = [
    'LTIGAE_BASE_URL' => $CFG->wwwroot,
    'LTIGAE_TEACHER' => $teacher->username,
    'LTIGAE_ASSISTANT' => $assistant->username,
    'LTIGAE_PASSWORD' => $password,
    'LTIGAE_COURSE' => $lticourse->id,
    'LTIGAE_BACKFILL_COURSE' => $backfillcourse->id,
    'LTIGAE_NOGROUPS_COURSE' => $nogroups->id,
    'LTIGAE_NOTOOLS_COURSE' => $notools->id,
    // Moodle 5.1+ keeps admin/cli outside the public/ web root ($CFG->root); Moodle 4.5 has no $CFG->root.
    'LTIGAE_MOODLE_DIR' => $CFG->root ?? $CFG->dirroot,
];
foreach ($exports as $key => $value) {
    echo "export {$key}='{$value}'\n";
}
