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

namespace tool_ltigroupautoenrol;

use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\tests\failing_observer;

/**
 * Error boundary of the enrolment observer: a real LTI enrolment with forced plugin failures.
 *
 * The observer is registered for core's user_enrolment_created event (as in db/events.php) in a
 * variant whose assignment and failure recording can be made to fail. Every failure must leave
 * the enrolment intact, be counted for the course and be logged with course id and exception
 * class only; a later backfill adds the missing membership and clears the count.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\observer
 * @covers     \tool_ltigroupautoenrol\local\event_reporter
 */
final class observer_failure_test extends \advanced_testcase {
    /** @var string|false Previous error_log setting. */
    private $errorlog;

    /**
     * Registers the failing observer and keeps the server log out of the test output.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/failing_observer.php');
        require_once(__DIR__ . '/fixtures/event_recorder.php');
        $this->resetAfterTest();
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\core\event\user_enrolment_created',
            'callback' => '\tool_ltigroupautoenrol\tests\failing_observer::user_is_enrolled',
        ], [
            'eventname' => '\tool_ltigroupautoenrol\event\group_assignment_failed',
            'callback' => '\tool_ltigroupautoenrol\tests\event_recorder::record',
        ]]);
        \tool_ltigroupautoenrol\tests\event_recorder::$events = [];
        failing_observer::$assignfailure = null;
        failing_observer::$recordfails = false;
        $this->errorlog = ini_set('error_log', '/dev/null');
    }

    /**
     * Restores the log setting and the observer state.
     *
     * @return void
     */
    protected function tearDown(): void {
        ini_set('error_log', (string) $this->errorlog);
        failing_observer::$assignfailure = null;
        failing_observer::$recordfails = false;
        parent::tearDown();
    }

    /**
     * Failures raised by the resolver or the group API (database exception, PHP error, coding exception).
     *
     * @return array
     */
    public static function failure_provider(): array {
        return [
            'database exception' => [new \dml_read_exception('simulated')],
            'PHP error (not caught by core)' => [new \TypeError('simulated')],
            'coding exception' => [new \coding_exception('simulated')],
        ];
    }

    /**
     * A failing assignment does not affect the enrolment; it is counted and logged, and a backfill repairs it.
     *
     * @dataProvider failure_provider
     * @param \Throwable $failure
     */
    public function test_failure_keeps_enrolment_and_is_counted(\Throwable $failure): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $course = $gen->create_course();
        $tool = $plugingen->create_lti_tool($course->id);
        $group = (int) $gen->create_group(['courseid' => $course->id])->id;
        $config = $plugingen->create_config($course->id, [$tool->id => [$group]]);
        $user = $gen->create_user();

        failing_observer::$assignfailure = $failure;
        $plugingen->enrol_via_lti($tool, $user->id);

        $this->assertTrue($DB->record_exists('user_enrolments', ['enrolid' => $tool->enrolid, 'userid' => $user->id]));
        $this->assertFalse(groups_is_member($group, $user->id));
        $this->assertDebuggingCalled(
            "tool_ltigroupautoenrol: assignment of a new LTI enrolment in course {$course->id} failed: " . get_class($failure)
        );
        $this->assertSame(1, config_repository::get_event_errors($course->id)->count);
        // The failure is reported as event (ids and the class basename only; tool not resolved: 0).
        $events = \tool_ltigroupautoenrol\tests\event_recorder::$events;
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\tool_ltigroupautoenrol\event\group_assignment_failed::class, $events[0]);
        $this->assertEquals($user->id, $events[0]->relateduserid);
        $this->assertEquals($tool->enrolid, $events[0]->objectid);
        $this->assertSame(
            ['source' => 'event', 'code' => \tool_ltigroupautoenrol\local\event_reporter::code_of($failure), 'errors' => 1,
                'toolid' => 0],
            $events[0]->other
        );

        // Asynchronous repair: the backfill assigns the user; afterwards nothing is missing and the count is cleared.
        failing_observer::$assignfailure = null;
        // Failures are cleared only if they happened before the check of the backfill (one-second resolution).
        $this->waitForSecond();
        $this->expectOutputRegex('/"added":1/');
        \tool_ltigroupautoenrol\task\backfill_task::create($course->id, $config->mapping->get_hash(), time() + 1)->execute();
        $this->assertTrue(groups_is_member($group, $user->id));
        $this->assertNull(config_repository::get_event_errors($course->id));
    }

    /**
     * Even if the failure can not be recorded, the enrolment stays intact and the log says so.
     */
    public function test_failure_of_status_persistence_is_contained(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $course = $gen->create_course();
        $tool = $plugingen->create_lti_tool($course->id);
        $plugingen->create_config($course->id, [$tool->id => [(int) $gen->create_group(['courseid' => $course->id])->id]]);
        $user = $gen->create_user();

        failing_observer::$assignfailure = new \dml_read_exception('simulated');
        failing_observer::$recordfails = true;
        $plugingen->enrol_via_lti($tool, $user->id);

        $this->assertTrue($DB->record_exists('user_enrolments', ['enrolid' => $tool->enrolid, 'userid' => $user->id]));
        $this->assertDebuggingCalled(
            "tool_ltigroupautoenrol: assignment of a new LTI enrolment in course {$course->id} failed: dml_read_exception"
                . ' (the failure could not be recorded: dml_write_exception)'
        );
        $this->assertNull(config_repository::get_event_errors($course->id));
    }

    /**
     * Without a failure the registered observer assigns as usual and records nothing.
     */
    public function test_success_path_is_unchanged(): void {
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $course = $gen->create_course();
        $tool = $plugingen->create_lti_tool($course->id);
        $group = (int) $gen->create_group(['courseid' => $course->id])->id;
        $plugingen->create_config($course->id, [$tool->id => [$group]]);
        $user = $gen->create_user();

        $plugingen->enrol_via_lti($tool, $user->id);

        $this->assertTrue(groups_is_member($group, $user->id));
        $this->assertDebuggingNotCalled();
        $this->assertNull(config_repository::get_event_errors($course->id));
    }
}
