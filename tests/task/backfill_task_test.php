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

namespace tool_ltigroupautoenrol\task;

/**
 * Tests of the backfill adhoc task.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\task\backfill_task
 */
final class backfill_task_test extends \advanced_testcase {
    /**
     * Creates a course with one mapped tool, one group and one existing (unassigned) LTI participant.
     *
     * @return array [course, tool, groupid, userid, generator]
     */
    private function create_scenario(): array {
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $course = $gen->create_course();
        $tool = $plugingen->create_lti_tool($course->id);
        $groupid = $gen->create_group(['courseid' => $course->id])->id;
        $user = $gen->create_user();
        $plugingen->enrol_via_lti($tool, $user->id);
        return [$course, $tool, $groupid, $user->id, $plugingen];
    }

    /**
     * The queued task assigns the existing participant; queueing twice yields one task.
     */
    public function test_task_assigns_and_is_deduplicated(): void {
        $this->resetAfterTest();
        [$course, $tool, $groupid, $userid, $plugingen] = $this->create_scenario();
        $config = $plugingen->create_config($course->id, [$tool->id => [$groupid]]);

        \core\task\manager::queue_adhoc_task(backfill_task::create($course->id, $config->mapping->get_hash()), true);
        \core\task\manager::queue_adhoc_task(backfill_task::create($course->id, $config->mapping->get_hash()), true);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(backfill_task::class));
        $this->assertTrue(backfill_task::is_pending($course->id));

        $this->expectOutputRegex('/"added":1/');
        $this->runAdhocTasks(backfill_task::class);

        $this->assertTrue(groups_is_member($groupid, $userid));
        $this->assertFalse(backfill_task::is_pending($course->id));
    }

    /**
     * If the configuration changed after confirmation, the task does nothing.
     */
    public function test_changed_configuration_is_not_executed(): void {
        $this->resetAfterTest();
        [$course, $tool, $groupid, $userid, $plugingen] = $this->create_scenario();
        $confirmed = $plugingen->create_config($course->id, [$tool->id => [$groupid]]);
        $othergroup = $this->getDataGenerator()->create_group(['courseid' => $course->id])->id;
        $plugingen->create_config($course->id, [$tool->id => [$othergroup]]);

        $task = backfill_task::create($course->id, $confirmed->mapping->get_hash());
        $this->expectOutputRegex('/changed or disabled, backfill skipped/');
        $task->execute();

        $this->assertFalse(groups_is_member($groupid, $userid));
        $this->assertFalse(groups_is_member($othergroup, $userid));
    }

    /**
     * A deleted course is skipped without error.
     */
    public function test_deleted_course(): void {
        $this->resetAfterTest();
        $task = backfill_task::create(-1, sha1(''));
        $this->expectOutputRegex('/does not exist/');
        $task->execute();
    }

    /**
     * A concurrent run of the same course (lock held) makes the task fail, so it is retried later.
     */
    public function test_locked_course_is_retried(): void {
        global $CFG;
        $this->resetAfterTest();
        // Advisory locks of the Postgres factory are re-entrant within one process; the record
        // lock factory is not, so it can simulate a concurrent run.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        [$course, $tool, $groupid, , $plugingen] = $this->create_scenario();
        $config = $plugingen->create_config($course->id, [$tool->id => [$groupid]]);
        $lock = \core\lock\lock_config::get_lock_factory('tool_ltigroupautoenrol_backfill')->get_lock('course_' . $course->id, 0);
        $this->assertNotFalse($lock);
        try {
            backfill_task::create($course->id, $config->mapping->get_hash())->execute();
            $this->fail('Expected the busy lock to fail the task');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_backfilllocked', $e->errorcode);
        } finally {
            $lock->release();
        }
    }
}
