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

use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\local\config_repository;

/**
 * End-to-end tests of the event observers.
 *
 * Every test uses real enrol_lti instances and real enrolments, so the user_enrolment_created
 * event, the record snapshot and enrol_lti\helper::get_lti_tools() are part of the tested path.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\observer
 * @covers     \tool_ltigroupautoenrol\local\assignment_service
 * @covers     \tool_ltigroupautoenrol\local\lti_resolver
 */
final class observer_test extends \advanced_testcase {
    /** @var \tool_ltigroupautoenrol_generator */
    private $plugingenerator;

    /** @var \stdClass */
    private $course;

    /**
     * Sets up the test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/group/lib.php');
        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('tool_ltigroupautoenrol');
        $this->course = $this->getDataGenerator()->create_course();
    }

    /**
     * Creates a group in the test course.
     *
     * @param string $name
     * @param int|null $courseid Defaults to the test course.
     * @return int Group id.
     */
    private function create_group(string $name, ?int $courseid = null): int {
        return $this->getDataGenerator()->create_group(['courseid' => $courseid ?? $this->course->id, 'name' => $name])->id;
    }

    /**
     * A real LTI 1.3 enrolment adds the user to all groups mapped to the tool.
     */
    public function test_lti_enrolment_adds_user_to_mapped_groups(): void {
        $tool = $this->plugingenerator->create_lti_tool($this->course->id);
        $groupa = $this->create_group('A');
        $groupb = $this->create_group('B');
        $groupc = $this->create_group('C');
        $this->plugingenerator->create_config($this->course->id, [$tool->id => [$groupa, $groupb]]);

        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool, $user->id);

        $this->assertTrue(groups_is_member($groupa, $user->id));
        $this->assertTrue(groups_is_member($groupb, $user->id));
        $this->assertFalse(groups_is_member($groupc, $user->id));
    }

    /**
     * Users of different tools end up in the groups of their own tool only.
     */
    public function test_multiple_tools(): void {
        $tool1 = $this->plugingenerator->create_lti_tool($this->course->id);
        $tool2 = $this->plugingenerator->create_lti_tool($this->course->id);
        $group1 = $this->create_group('Consumer 1');
        $group2 = $this->create_group('Consumer 2');
        $this->plugingenerator->create_config($this->course->id, [$tool1->id => [$group1], $tool2->id => [$group2]]);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool1, $user1->id);
        $this->plugingenerator->enrol_via_lti($tool2, $user2->id);

        $this->assertTrue(groups_is_member($group1, $user1->id));
        $this->assertFalse(groups_is_member($group2, $user1->id));
        $this->assertTrue(groups_is_member($group2, $user2->id));
        $this->assertFalse(groups_is_member($group1, $user2->id));
    }

    /**
     * Enrolments through an unmapped tool or another enrolment method are ignored.
     */
    public function test_unmapped_tool_and_manual_enrolment_are_ignored(): void {
        $mapped = $this->plugingenerator->create_lti_tool($this->course->id);
        $unmapped = $this->plugingenerator->create_lti_tool($this->course->id);
        $group = $this->create_group('A');
        $this->plugingenerator->create_config($this->course->id, [$mapped->id => [$group]]);

        $ltiuser = $this->getDataGenerator()->create_user();
        $manualuser = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($unmapped, $ltiuser->id);
        $this->getDataGenerator()->enrol_user($manualuser->id, $this->course->id, 'student', 'manual');

        $this->assertFalse(groups_is_member($group, $ltiuser->id));
        $this->assertFalse(groups_is_member($group, $manualuser->id));
    }

    /**
     * LTI 1.1 tools are not supported and are ignored even if their id is in the mapping.
     */
    public function test_legacy_lti_version_is_ignored(): void {
        $tool = $this->plugingenerator->create_lti_tool($this->course->id, ['ltiversion' => 'LTI-1p0/LTI-2p0']);
        $group = $this->create_group('A');
        $this->plugingenerator->create_config($this->course->id, [$tool->id => [$group]]);

        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool, $user->id);

        $this->assertFalse(groups_is_member($group, $user->id));
    }

    /**
     * Disabled configuration: no assignment and no LTI lookup (exactly one query for the configuration).
     */
    public function test_feature_off(): void {
        global $DB;
        $tool = $this->plugingenerator->create_lti_tool($this->course->id);
        $group = $this->create_group('A');
        $this->plugingenerator->create_config($this->course->id, [$tool->id => [$group]], false);

        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool, $user->id);
        $this->assertFalse(groups_is_member($group, $user->id));

        $ue = $DB->get_record('user_enrolments', ['enrolid' => $tool->enrolid, 'userid' => $user->id], '*', MUST_EXIST);
        $reads = $DB->perf_get_reads();
        $this->assertNull(assignment_service::handle_new_enrolment($this->course->id, $ue));
        $this->assertSame(1, $DB->perf_get_reads() - $reads);
    }

    /**
     * Suspended and not yet started enrolments are not assigned.
     */
    public function test_suspended_and_future_enrolments_are_ignored(): void {
        $tool = $this->plugingenerator->create_lti_tool($this->course->id);
        $group = $this->create_group('A');
        $this->plugingenerator->create_config($this->course->id, [$tool->id => [$group]]);

        $suspended = $this->getDataGenerator()->create_user();
        $future = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool, $suspended->id, ENROL_USER_SUSPENDED);
        $this->plugingenerator->enrol_via_lti($tool, $future->id, ENROL_USER_ACTIVE, time() + DAYSECS);

        $this->assertFalse(groups_is_member($group, $suspended->id));
        $this->assertFalse(groups_is_member($group, $future->id));
    }

    /**
     * Deleted groups and groups of other courses are never used, even if they are in the stored mapping.
     */
    public function test_deleted_and_foreign_groups_are_skipped(): void {
        global $DB;
        $tool = $this->plugingenerator->create_lti_tool($this->course->id);
        $existing = $this->create_group('Existing');
        $deleted = $this->create_group('Deleted');
        $othercourse = $this->getDataGenerator()->create_course();
        $foreign = $this->create_group('Foreign', $othercourse->id);
        // Write the stale/foreign ids directly, bypassing the form and the pruning observers.
        $DB->insert_record('tool_ltigroupautoenrol', (object) [
            'courseid' => $this->course->id,
            'enable_enrol' => 1,
            'settings' => json_encode([$tool->id => [$existing, $deleted, $foreign]]),
        ]);
        $DB->delete_records('groups', ['id' => $deleted]);

        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool, $user->id);

        $this->assertTrue(groups_is_member($existing, $user->id));
        $this->assertFalse(groups_is_member($foreign, $user->id));
        $this->assertFalse($DB->record_exists('groups_members', ['groupid' => $deleted]));
    }

    /**
     * A corrupt stored mapping is treated as "no mapping" and never breaks the enrolment.
     */
    public function test_corrupt_mapping_does_not_break_enrolment(): void {
        global $DB;
        $tool = $this->plugingenerator->create_lti_tool($this->course->id);
        $DB->insert_record('tool_ltigroupautoenrol', (object) [
            'courseid' => $this->course->id,
            'enable_enrol' => 1,
            'settings' => '{"' . $tool->id . '":[',
        ]);

        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool, $user->id);

        $this->assertDebuggingCalled();
        $this->assertTrue(is_enrolled(\context_course::instance($this->course->id), $user->id));
        $this->assertSame(0, $DB->count_records('groups_members', ['userid' => $user->id]));
    }

    /**
     * An existing membership is kept and not duplicated.
     */
    public function test_existing_membership_is_idempotent(): void {
        global $DB;
        $tool = $this->plugingenerator->create_lti_tool($this->course->id);
        $group = $this->create_group('A');
        $this->plugingenerator->create_config($this->course->id, [$tool->id => [$group]]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
        groups_add_member($group, $user->id);

        $this->plugingenerator->enrol_via_lti($tool, $user->id);

        $this->assertSame(1, $DB->count_records('groups_members', ['groupid' => $group, 'userid' => $user->id]));
    }

    /**
     * Course deletion removes the plugin configuration.
     */
    public function test_course_deletion_removes_configuration(): void {
        global $DB;
        $tool = $this->plugingenerator->create_lti_tool($this->course->id);
        $this->plugingenerator->create_config($this->course->id, [$tool->id => [$this->create_group('A')]]);

        delete_course($this->course, false);

        $this->assertFalse($DB->record_exists('tool_ltigroupautoenrol', ['courseid' => $this->course->id]));
    }

    /**
     * Deleting an LTI enrol instance or a group prunes the mapping; memberships stay untouched.
     */
    public function test_tool_and_group_deletion_prune_mapping(): void {
        global $DB;
        $tool1 = $this->plugingenerator->create_lti_tool($this->course->id);
        $tool2 = $this->plugingenerator->create_lti_tool($this->course->id);
        $group1 = $this->create_group('A');
        $group2 = $this->create_group('B');
        $this->plugingenerator->create_config($this->course->id, [$tool1->id => [$group1, $group2], $tool2->id => [$group2]]);
        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool1, $user->id);
        $this->assertTrue(groups_is_member($group1, $user->id));

        $instance = $DB->get_record('enrol', ['id' => $tool2->enrolid], '*', MUST_EXIST);
        enrol_get_plugin('lti')->delete_instance($instance);
        $this->assertSame([$tool1->id => [$group1, $group2]], config_repository::get($this->course->id)->mapping->to_array());

        groups_delete_group($group2);
        $this->assertSame([$tool1->id => [$group1]], config_repository::get($this->course->id)->mapping->to_array());
        $this->assertTrue(groups_is_member($group1, $user->id));
    }

    /**
     * Disabling the feature never removes memberships that were added before.
     */
    public function test_disabling_keeps_memberships(): void {
        $tool = $this->plugingenerator->create_lti_tool($this->course->id);
        $group = $this->create_group('A');
        $this->plugingenerator->create_config($this->course->id, [$tool->id => [$group]]);
        $user = $this->getDataGenerator()->create_user();
        $this->plugingenerator->enrol_via_lti($tool, $user->id);

        $this->plugingenerator->create_config($this->course->id, [$tool->id => [$group]], false);

        $this->assertTrue(groups_is_member($group, $user->id));
    }
}
