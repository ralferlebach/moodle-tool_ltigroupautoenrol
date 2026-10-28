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

namespace tool_ltigroupautoenrol\event;

use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\local\event_reporter;
use tool_ltigroupautoenrol\tests\event_recorder;

/**
 * Tests of the group assignment events (contract read by tool_ltidiagnostics, see docs/EVENTS.md).
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\event\group_assignment_base
 * @covers     \tool_ltigroupautoenrol\event\group_assignment_completed
 * @covers     \tool_ltigroupautoenrol\event\group_assignment_failed
 * @covers     \tool_ltigroupautoenrol\local\event_reporter
 * @covers     \tool_ltigroupautoenrol\local\assignment_service
 * @covers     \tool_ltigroupautoenrol\observer
 */
final class group_assignment_events_test extends \advanced_testcase {
    /** @var \tool_ltigroupautoenrol_generator */
    private $plugingen;

    /** @var \stdClass */
    private $course;

    /** @var \stdClass Mapped tool. */
    private $tool;

    /** @var int[] Groups A and B (mapped to the tool). */
    private $groups;

    /**
     * Creates a course with one LTI 1.3 tool mapped to two groups.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/group/lib.php');
        require_once(__DIR__ . '/../fixtures/event_recorder.php');
        event_recorder::$events = [];
        $gen = $this->getDataGenerator();
        $this->plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $this->course = $gen->create_course();
        $this->tool = $this->plugingen->create_lti_tool($this->course->id);
        foreach (['A', 'B'] as $name) {
            $this->groups[$name] = (int) $gen->create_group(['courseid' => $this->course->id, 'name' => "Group secret $name"])->id;
        }
    }

    /**
     * Stores the mapping tool => A, B.
     *
     * @param bool $enabled
     * @return \tool_ltigroupautoenrol\local\course_config
     */
    private function configure(bool $enabled = true) {
        return $this->plugingen->create_config($this->course->id, [$this->tool->id => array_values($this->groups)], $enabled);
    }

    /**
     * Registers the plugin's real enrolment observer and a recorder for its two events.
     *
     * An event sink can not be used for the enrolment path: a sink also stops the observers, so the plugin
     * would never see the enrolment.
     *
     * @param array $extra Additional observers.
     * @return void
     */
    private function record_events(array $extra = []): void {
        $recorder = '\\tool_ltigroupautoenrol\\tests\\event_recorder::record';
        \core\event\manager::phpunit_replace_observers(array_merge([
            [
                'eventname' => '\\core\\event\\user_enrolment_created',
                'callback' => '\\tool_ltigroupautoenrol\\observer::user_is_enrolled',
            ],
            ['eventname' => '\\' . group_assignment_completed::class, 'callback' => $recorder],
            ['eventname' => '\\' . group_assignment_failed::class, 'callback' => $recorder],
        ], $extra));
    }

    /**
     * Returns the events of this plugin from a list of events.
     *
     * @param \core\event\base[] $events
     * @param string $class
     * @return \core\event\base[]
     */
    private function of_class(array $events, string $class): array {
        return array_values(array_filter($events, fn($e) => $e instanceof $class));
    }

    /**
     * A real LTI enrolment triggers group_assignment_completed with ids and counters only.
     */
    public function test_new_lti_enrolment_triggers_completed(): void {
        $this->configure();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ulla', 'lastname' => 'Secret',
            'email' => 'ulla.secret@example.com', 'username' => 'ullasecret']);
        $this->record_events();

        $this->plugingen->enrol_via_lti($this->tool, $user->id);

        $events = event_recorder::$events;
        $completed = $this->of_class($events, group_assignment_completed::class);
        $this->assertCount(1, $completed);
        $this->assertCount(0, $this->of_class($events, group_assignment_failed::class));
        $event = $completed[0];
        $this->assertSame('tool_ltigroupautoenrol', $event->component);
        $this->assertSame('\tool_ltigroupautoenrol\event\group_assignment_completed', $event->eventname);
        $this->assertSame('c', $event->crud);
        $this->assertEquals(\core\event\base::LEVEL_OTHER, $event->edulevel);
        $this->assertEquals(\context_course::instance($this->course->id)->id, $event->contextid);
        $this->assertEquals($this->course->id, $event->courseid);
        $this->assertSame('enrol', $event->objecttable);
        $this->assertEquals($this->tool->enrolid, $event->objectid);
        $this->assertEquals($user->id, $event->relateduserid);
        $this->assertSame(
            ['source' => 'event', 'added' => 2, 'existing' => 0, 'errors' => 0, 'toolid' => (int) $this->tool->id],
            $event->other
        );
        $this->assertSame(get_string('event_group_assignment_completed', 'tool_ltigroupautoenrol'), $event::get_name());
        $this->assertStringContainsString("user with id '{$user->id}'", $event->get_description());
        $this->assertStringContainsString('id=' . $this->course->id, $event->get_url()->out(false));
        // No personal data beyond ids: neither in "other" nor in the description.
        $serialised = json_encode($event->get_data()) . $event->get_description();
        foreach (['Ulla', 'Secret', 'ullasecret', 'example.com', 'Group secret'] as $pii) {
            $this->assertStringNotContainsString($pii, $serialised);
        }
        // The assignment is unchanged by the reporting.
        $this->assertTrue(groups_is_member($this->groups['A'], $user->id));
        $this->assertTrue(groups_is_member($this->groups['B'], $user->id));
    }

    /**
     * An existing membership is reported as "existing".
     */
    public function test_existing_membership_is_counted(): void {
        $this->configure();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
        groups_add_member($this->groups['A'], $user->id);
        $this->record_events();

        $this->plugingen->enrol_via_lti($this->tool, $user->id);

        $completed = $this->of_class(event_recorder::$events, group_assignment_completed::class);
        $this->assertCount(1, $completed);
        $this->assertSame(1, $completed[0]->other['added']);
        $this->assertSame(1, $completed[0]->other['existing']);
    }

    /**
     * Not eligible, unmapped, disabled and non-LTI enrolments trigger none of the plugin's events.
     */
    public function test_no_event_without_assignment(): void {
        $gen = $this->getDataGenerator();
        $unmapped = $this->plugingen->create_lti_tool($this->course->id);
        $this->configure();
        $this->record_events();

        $this->plugingen->enrol_via_lti($this->tool, $gen->create_user()->id, ENROL_USER_SUSPENDED);
        $this->plugingen->enrol_via_lti($this->tool, $gen->create_user()->id, ENROL_USER_ACTIVE, time() + DAYSECS);
        $this->plugingen->enrol_via_lti($unmapped, $gen->create_user()->id);
        $gen->enrol_user($gen->create_user()->id, $this->course->id, 'student', 'manual');
        $this->configure(false);
        $this->plugingen->enrol_via_lti($this->tool, $gen->create_user()->id);

        $this->assertSame([], event_recorder::$events);
    }

    /**
     * Memberships that can not be added are reported by group_assignment_failed with a machine code.
     */
    public function test_failed_memberships_are_reported(): void {
        $config = $this->configure();
        // Not enrolled in the course: groups_add_member() refuses both memberships.
        $stranger = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();

        $result = assignment_service::assign_user($config, $this->tool, $stranger->id);
        event_reporter::user_result($this->course->id, $this->tool, $stranger->id, 'event', $result, false);

        $events = $sink->get_events();
        $sink->close();
        $failed = $this->of_class($events, group_assignment_failed::class);
        $this->assertCount(1, $failed);
        $this->assertEquals($stranger->id, $failed[0]->relateduserid);
        $this->assertEquals($this->tool->enrolid, $failed[0]->objectid);
        $this->assertSame(
            ['source' => 'event', 'code' => 'add_member_failed', 'errors' => 2, 'toolid' => (int) $this->tool->id],
            $failed[0]->other
        );
        $this->assertSame(get_string('event_group_assignment_failed', 'tool_ltigroupautoenrol'), $failed[0]::get_name());
        $this->assertStringContainsString('add_member_failed', $failed[0]->get_description());
        $completed = $this->of_class($events, group_assignment_completed::class);
        $this->assertCount(1, $completed);
        $this->assertSame(2, $completed[0]->other['errors']);
    }

    /**
     * A tool of another course is reported as failure "tool_course_mismatch" and nothing is added.
     */
    public function test_tool_of_other_course_is_reported(): void {
        $config = $this->configure();
        $othercourse = $this->getDataGenerator()->create_course();
        $foreigntool = $this->plugingen->create_lti_tool($othercourse->id);
        $foreigntool->id = $this->tool->id;
        $user = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();

        $result = assignment_service::assign_user($config, $foreigntool, $user->id);
        event_reporter::user_result($this->course->id, $foreigntool, $user->id, 'event', $result, false);

        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(0, $this->of_class($events, group_assignment_completed::class));
        $failed = $this->of_class($events, group_assignment_failed::class);
        $this->assertCount(1, $failed);
        $this->assertSame('tool_course_mismatch', $failed[0]->other['code']);
    }

    /**
     * The backfill reports only users whose memberships changed or failed.
     */
    public function test_backfill_reports_changes_only(): void {
        $gen = $this->getDataGenerator();
        $assigned = $gen->create_user();
        $missing = $gen->create_user();
        $this->plugingen->enrol_via_lti($this->tool, $assigned->id);
        $this->plugingen->enrol_via_lti($this->tool, $missing->id);
        $config = $this->configure();
        groups_add_member($this->groups['A'], $assigned->id);
        groups_add_member($this->groups['B'], $assigned->id);
        $sink = $this->redirectEvents();

        $result = assignment_service::backfill($config);

        $events = $sink->get_events();
        $sink->close();
        $this->assertSame(2, $result->added);
        $this->assertSame(2, $result->alreadymember);
        $completed = $this->of_class($events, group_assignment_completed::class);
        $this->assertCount(1, $completed);
        $this->assertEquals($missing->id, $completed[0]->relateduserid);
        $this->assertSame(
            ['source' => 'backfill', 'added' => 2, 'existing' => 0, 'errors' => 0, 'toolid' => (int) $this->tool->id],
            $completed[0]->other
        );
        // A repeated run changes nothing and logs nothing.
        $sink = $this->redirectEvents();
        assignment_service::backfill($config);
        $this->assertSame([], array_filter($sink->get_events(), fn($e) => $e->component === 'tool_ltigroupautoenrol'));
        $sink->close();
    }

    /**
     * An error that aborts the backfill of a tool is reported without a user and passed on (task API retry).
     */
    public function test_backfill_abort_is_reported_and_rethrown(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->plugingen->enrol_via_lti($this->tool, $user->id);
        $config = $this->configure();
        $this->record_events([
            ['eventname' => '\core\event\group_member_added', 'callback' => '\tool_ltigroupautoenrol\tests\event_recorder::fail'],
        ]);

        try {
            assignment_service::backfill($config);
            $this->fail('The error must be passed on.');
        } catch (\TypeError $e) {
            $this->assertSame('simulated', $e->getMessage());
        }

        // The error left core's dispatcher in "dispatching" state (it catches only exceptions of observers), so
        // the failure event is still in its buffer; it is taken from there and the dispatcher is reset.
        $buffer = new \ReflectionProperty(\core\event\manager::class, 'buffer');
        $buffer->setAccessible(true);
        $failed = $this->of_class(array_merge(event_recorder::$events, $buffer->getValue()), group_assignment_failed::class);
        \core\event\manager::phpunit_reset();
        $this->assertCount(1, $failed);
        $this->assertNull($failed[0]->relateduserid);
        $this->assertEquals($this->tool->enrolid, $failed[0]->objectid);
        $this->assertSame(
            ['source' => 'backfill', 'code' => 'TypeError', 'errors' => 1, 'toolid' => (int) $this->tool->id],
            $failed[0]->other
        );
        $this->assertStringContainsString('a backfill run', $failed[0]->get_description());
    }

    /**
     * With the standard log store, the events are stored under the plugin's component (read by tool_ltidiagnostics).
     */
    public function test_events_reach_the_standard_log(): void {
        global $DB;
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('jsonformat', 1, 'logstore_standard');
        get_log_manager(true);
        $this->configure();
        $user = $this->getDataGenerator()->create_user();

        $this->plugingen->enrol_via_lti($this->tool, $user->id);

        $logs = $DB->get_records('logstore_standard_log', ['component' => 'tool_ltigroupautoenrol']);
        $this->assertCount(1, $logs);
        $log = reset($logs);
        $this->assertSame('\tool_ltigroupautoenrol\event\group_assignment_completed', $log->eventname);
        $this->assertEquals($user->id, $log->relateduserid);
        $this->assertEquals($this->tool->enrolid, $log->objectid);
        $this->assertEquals($this->course->id, $log->courseid);
        $other = json_decode($log->other, true);
        $this->assertSame('event', $other['source']);
        $this->assertSame((int) $this->tool->id, $other['toolid']);
        get_log_manager(true);
    }

    /**
     * Payloads: contract violations and two valid cases.
     *
     * @return array
     */
    public static function invalid_payload_provider(): array {
        $other = ['source' => 'event', 'added' => 1, 'existing' => 0, 'errors' => 0, 'toolid' => 3];
        $failed = ['source' => 'backfill', 'code' => 'TypeError', 'errors' => 1, 'toolid' => 3];
        return [
            'missing key' => [group_assignment_completed::class, array_diff_key($other, ['added' => 1]), true, false],
            'additional key (personal data)' => [group_assignment_completed::class, $other + ['email' => 'a@b.c'], true, false],
            'group name' => [group_assignment_failed::class, $failed + ['groupname' => 'G'], true, false],
            'negative counter' => [group_assignment_completed::class, ['added' => -1] + $other, true, false],
            'string counter' => [group_assignment_completed::class, ['errors' => '1'] + $other, true, false],
            'unknown source' => [group_assignment_completed::class, ['source' => 'cron'] + $other, true, false],
            'free text code' => [group_assignment_failed::class, ['code' => 'User a@b.c failed'] + $failed, true, false],
            'no user (completed)' => [group_assignment_completed::class, $other, false, false],
            'no user (failed, allowed)' => [group_assignment_failed::class, $failed, false, true],
            'valid completed' => [group_assignment_completed::class, $other, true, true],
        ];
    }

    /**
     * validate_data() enforces the contract.
     *
     * @dataProvider invalid_payload_provider
     * @param string $class
     * @param array $other
     * @param bool $withuser
     * @param bool $valid
     */
    public function test_validate_data(string $class, array $other, bool $withuser, bool $valid): void {
        $data = [
            'context' => \context_course::instance($this->course->id),
            'objectid' => $this->tool->enrolid,
            'other' => $other,
        ];
        if ($withuser) {
            $data['relateduserid'] = 2;
        }
        if (!$valid) {
            $this->expectException(\coding_exception::class);
        }
        $event = $class::create($data);
        $this->assertInstanceOf($class, $event);
    }

    /**
     * Only course contexts and an enrol instance id are accepted.
     */
    public function test_context_and_object_are_required(): void {
        $other = ['source' => 'event', 'code' => 'x', 'errors' => 1, 'toolid' => 0];
        try {
            group_assignment_failed::create(['context' => \context_system::instance(), 'objectid' => 1, 'other' => $other]);
            $this->fail('System context accepted');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('course context', $e->getMessage());
        }
        $this->expectException(\coding_exception::class);
        group_assignment_failed::create(['context' => \context_course::instance($this->course->id), 'other' => $other]);
    }

    /**
     * A failure to trigger an event never breaks the caller; developers get a debugging message.
     */
    public function test_reporting_failure_is_contained(): void {
        event_reporter::failure(-5, 1, 1, null, 'event', 'x', 1);
        $this->assertDebuggingCalled();
    }

    /**
     * Restore mappings of the log entries.
     */
    public function test_restore_mappings(): void {
        $this->assertSame(['db' => 'enrol', 'restore' => 'enrol'], group_assignment_completed::get_objectid_mapping());
        $this->assertSame(['toolid' => \core\event\base::NOT_MAPPED], group_assignment_failed::get_other_mapping());
    }

    /**
     * The plugin does not depend on tool_ltidiagnostics (or any other family plugin): no reference in the shipped code.
     */
    public function test_no_dependency_on_family_plugins(): void {
        global $CFG;
        $root = $CFG->dirroot . '/admin/tool/ltigroupautoenrol';
        $plugin = new \stdClass();
        require($root . '/version.php');
        $this->assertEmpty($plugin->dependencies ?? []);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root));
            if ($file->getExtension() !== 'php' || preg_match('~^/(tests|\.github|docs|node_modules)/~', $path)) {
                continue;
            }
            // Code only: comments may name the family plugins that read the events.
            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= is_array($token) ? $token[1] : $token;
                }
            }
            $this->assertDoesNotMatchRegularExpression(
                '/ltidiagnostics|ltiidentity|ltipseudonym|ltiaccountmerge|ltixsembed|lticompletionsync/',
                $code,
                $path
            );
        }
    }
}
