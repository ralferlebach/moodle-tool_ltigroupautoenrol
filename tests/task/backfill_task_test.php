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
        // Not "done": the run is incomplete and the task API retries it.
        $last = config_repository::get_last_backfill($course->id);
        $this->assertSame([config_repository::BACKFILL_PARTIAL, 1, 2], [$last->status, $last->added, $last->errors]);

        // While the retry is queued, the page shows the partial result as a warning.
        \core\task\manager::queue_adhoc_task(backfill_task::create($course->id, $config->mapping->get_hash(), time()));
        [$message, $type] = backfill_status::get_message($course->id);
        $this->assertSame(\core\output\notification::NOTIFY_WARNING, $type);
        $this->assertStringContainsString('retried automatically', $message);

        // Retry: the membership exists already, nothing is duplicated, and only now the run is "done".
        $this->expectOutputRegex('/"alreadymember":1/');
        backfill_task::create($course->id, $config->mapping->get_hash())->execute();
        $this->assertTrue(groups_is_member($groupid, $userid));
        $last = config_repository::get_last_backfill($course->id);
        $this->assertSame([config_repository::BACKFILL_DONE, 0], [$last->status, $last->errors]);
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

    /**
     * A failure of the enrolment observer after the preview stays visible after the backfill (the approved set
     * does not contain that participant); failures are cleared once nobody eligible misses a membership.
     */
    public function test_event_errors_are_cleared_only_when_nothing_is_missing(): void {
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
        // The observer failed for the late enrolment after the preview (time 2000).
        config_repository::record_event_errors($course->id, 1, 3000);

        $this->expectOutputRegex('/"added":1/');
        backfill_task::create($course->id, $config->mapping->get_hash(), 2000)->execute();
        $this->assertTrue(groups_is_member($groupid, $userid));
        $this->assertFalse(groups_is_member($groupid, $late->id));
        $this->assertSame(1, config_repository::get_event_errors($course->id)->count);

        // A later run without time limit assigns the late participant; now nothing is missing and the failure is cleared.
        backfill_task::create($course->id, $config->mapping->get_hash(), time())->execute();
        $this->assertTrue(groups_is_member($groupid, $late->id));
        $this->assertNull(config_repository::get_event_errors($course->id));
    }

    /**
     * An exception during the run records "failed" instead of leaving "running", and is rethrown for the task API;
     * the retry succeeds and records "done".
     */
    public function test_exception_records_failed_and_retry_recovers(): void {
        $this->resetAfterTest();
        [$course, $tool, $groupid, $userid, $plugingen] = $this->create_scenario();
        $config = $plugingen->create_config($course->id, [$tool->id => [$groupid]]);
        $task = new class extends backfill_task {
            /**
             * Simulates a database failure in the middle of the run.
             *
             * @param course_config $config
             * @param int|null $previewtime
             * @return assignment_result
             */
            protected function run_backfill(course_config $config, ?int $previewtime): assignment_result {
                throw new \dml_write_exception('simulated');
            }
        };
        $task->set_custom_data(['courseid' => $course->id, 'hash' => $config->mapping->get_hash(), 'previewtime' => time()]);

        ob_start();
        try {
            $task->execute();
            $this->fail('Expected the exception to reach the task API');
        } catch (\dml_write_exception $e) {
            $this->assertStringContainsString('simulated', $e->debuginfo);
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('failed: dml_write_exception', $output);
        $this->assertSame(config_repository::BACKFILL_FAILED, config_repository::get_last_backfill($course->id)->status);
        // The lock was released, so the retry can run.
        $this->expectOutputRegex('/"added":1/');
        backfill_task::create($course->id, $config->mapping->get_hash())->execute();
        $this->assertSame(config_repository::BACKFILL_DONE, config_repository::get_last_backfill($course->id)->status);
        $this->assertTrue(groups_is_member($groupid, $userid));
    }

    /**
     * is_pending() only finds tasks of the given course, also among many queued tasks of other courses.
     */
    public function test_is_pending_is_course_specific(): void {
        $this->resetAfterTest();
        $courses = [];
        for ($i = 0; $i < 12; $i++) {
            $courses[$i] = $this->getDataGenerator()->create_course();
            if ($i !== 1) {
                \core\task\manager::queue_adhoc_task(backfill_task::create($courses[$i]->id, sha1((string) $i), 1000));
            }
        }
        foreach ($courses as $i => $course) {
            $this->assertSame($i !== 1, backfill_task::is_pending($course->id), "course index $i");
        }
        // Queued courses with ids 1x must not match the site course id 1 (prefix of the JSON value).
        $this->assertGreaterThan(10, (int) end($courses)->id);
        $this->assertFalse(backfill_task::is_pending((int) SITEID));
    }

    /**
     * A task counts as pending while it is queued, running (locked) or waiting for a retry after a failure;
     * after it finished it is no longer pending.
     */
    public function test_pending_states(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $tool, $groupid, , $plugingen] = $this->create_scenario();
        $config = $plugingen->create_config($course->id, [$tool->id => [$groupid]]);
        \core\task\manager::queue_adhoc_task(backfill_task::create($course->id, $config->mapping->get_hash(), time()));
        $record = $DB->get_record('task_adhoc', ['classname' => '\\' . backfill_task::class], '*', MUST_EXIST);
        $this->assertTrue(backfill_task::is_pending($course->id), 'queued');

        $DB->update_record('task_adhoc', (object) ['id' => $record->id, 'timestarted' => time(), 'hostname' => 'host', 'pid' => 1]);
        $this->assertTrue(backfill_task::is_pending($course->id), 'running (locked)');

        $DB->update_record('task_adhoc', (object) [
            'id' => $record->id, 'timestarted' => null, 'hostname' => null, 'pid' => null,
            'faildelay' => 60, 'nextruntime' => time() + 60,
        ]);
        $this->assertTrue(backfill_task::is_pending($course->id), 'waiting for a retry');

        $DB->set_field('task_adhoc', 'nextruntime', time() - 1, ['id' => $record->id]);
        $this->expectOutputRegex('/"added":1/');
        $this->runAdhocTasks(backfill_task::class);
        $this->assertFalse(backfill_task::is_pending($course->id), 'finished');
    }
}
