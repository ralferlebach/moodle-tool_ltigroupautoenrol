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

use tool_ltigroupautoenrol\local\assignment_result;
use tool_ltigroupautoenrol\local\backfill_status;
use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\course_config;

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

        $previewtime = time();
        \core\task\manager::queue_adhoc_task(backfill_task::create($course->id, $config->mapping->get_hash(), $previewtime), true);
        \core\task\manager::queue_adhoc_task(backfill_task::create($course->id, $config->mapping->get_hash(), $previewtime), true);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(backfill_task::class));
        $this->assertTrue(backfill_task::is_pending($course->id));

        $this->expectOutputRegex('/"added":1/');
        $this->runAdhocTasks(backfill_task::class);

        $this->assertTrue(groups_is_member($groupid, $userid));
        $this->assertFalse(backfill_task::is_pending($course->id));
        $lastrun = config_repository::get_last_backfill($course->id);
        $this->assertSame(config_repository::BACKFILL_DONE, $lastrun->status);
        $this->assertSame(1, $lastrun->added);
        $this->assertSame(0, $lastrun->errors);
        $this->assertGreaterThan(0, $lastrun->time);
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
        // The skip is visible to the teacher, not only in the cron log.
        $this->assertSame(config_repository::BACKFILL_SKIPPED, config_repository::get_last_backfill($course->id)->status);
        $this->assertStringContainsString('was not carried out', backfill_status::get_message($course->id)[0]);
    }

    /**
     * Only the set approved in the preview is assigned: enrolments created after the preview are not
     * touched by the backfill (the enrolment observer handles them).
     */
    public function test_only_the_approved_set_is_assigned(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $tool, $groupid, $userid, $plugingen] = $this->create_scenario();
        $DB->execute(
            'UPDATE {user_enrolments} SET timecreated = 1000, timemodified = 1000, timestart = 1000 WHERE userid = ?',
            [$userid]
        );
        $plugingen->create_config($course->id, [$tool->id => [$groupid]], false);
        $late = $this->getDataGenerator()->create_user();
        $plugingen->enrol_via_lti($tool, $late->id);
        $DB->execute('UPDATE {user_enrolments} SET timecreated = 3000, timemodified = 3000 WHERE userid = ?', [$late->id]);
        $config = $plugingen->create_config($course->id, [$tool->id => [$groupid]], true);

        $this->expectOutputRegex('/"added":1/');
        backfill_task::create($course->id, $config->mapping->get_hash(), 2000)->execute();

        $this->assertTrue(groups_is_member($groupid, $userid));
        $this->assertFalse(groups_is_member($groupid, $late->id));
    }

    /**
     * Partial failures are recorded and make the task fail, so the task API retries it; the retry is idempotent.
     */
    public function test_partial_failure_is_recorded_and_retried(): void {
        $this->resetAfterTest();
        [$course, $tool, $groupid, $userid, $plugingen] = $this->create_scenario();
        $config = $plugingen->create_config($course->id, [$tool->id => [$groupid]]);
        $task = new class extends backfill_task {
            /**
             * Simulates two failed memberships.
             *
             * @param course_config $config
             * @param int|null $previewtime
             * @return assignment_result
             */
            protected function run_backfill(course_config $config, ?int $previewtime): assignment_result {
                $result = parent::run_backfill($config, $previewtime);
                $result->errors = 2;
                return $result;
            }
        };
        $task->set_custom_data(['courseid' => $course->id, 'hash' => $config->mapping->get_hash(), 'previewtime' => time()]);

        ob_start();
        try {
            $task->execute();
            $this->fail('Expected a partial failure to fail the task');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_backfillpartial', $e->errorcode);
        } finally {
            ob_end_clean();
        }
        $last = config_repository::get_last_backfill($course->id);
        $this->assertSame([config_repository::BACKFILL_DONE, 1, 2], [$last->status, $last->added, $last->errors]);

        // Retry: the membership exists already, nothing is duplicated.
        $this->expectOutputRegex('/"alreadymember":1/');
        backfill_task::create($course->id, $config->mapping->get_hash())->execute();
        $this->assertTrue(groups_is_member($groupid, $userid));
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
