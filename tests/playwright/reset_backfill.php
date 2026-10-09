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
 * CLI helper for the Playwright tests: restores the seeded state of the "Backfill course".
 *
 * Removes the group memberships of the course, queued backfill tasks of the course and the status
 * of the last backfill run, so the backfill test starts from the same state on every attempt
 * (including Playwright retries).
 *
 * Usage: php reset_backfill.php <courseid>
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../../config.php');
require_once($CFG->dirroot . '/group/lib.php');

$courseid = (int) ($argv[1] ?? 0);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

foreach (groups_get_all_groups($course->id) as $group) {
    foreach (groups_get_members($group->id, 'u.id') as $member) {
        groups_remove_member($group->id, $member->id);
    }
}
foreach (\core\task\manager::get_adhoc_tasks(\tool_ltigroupautoenrol\task\backfill_task::class) as $task) {
    if ((int) ($task->get_custom_data()->courseid ?? 0) === (int) $course->id) {
        $DB->delete_records('task_adhoc', ['id' => $task->get_id()]);
    }
}
$DB->set_field('tool_ltigroupautoenrol', 'backfill_time', 0, ['courseid' => $course->id]);
$DB->set_field('tool_ltigroupautoenrol', 'backfill_result', null, ['courseid' => $course->id]);
echo "Backfill course {$course->id} reset\n";
