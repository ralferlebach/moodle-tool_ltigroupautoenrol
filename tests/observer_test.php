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
 * Event handling tests for LTI group auto enrolment.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2024 Your Name <your.email@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\event\user_enrolment_created;

/**
 * Tests for event handling of automatic group assignment in LTI.
 *
 * This test class verifies that the LTI group auto enrolment tool correctly
 * handles user enrolment events and assigns users to appropriate groups
 * based on LTI deployment mappings.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2024 Your Name <your.email@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\observer
 */
class tool_ltigroupautoenrol_observer_test extends advanced_test {

    /**
     * Main configuration table name.
     *
     * @var string TABLE Name of the main plugin configuration table
     */
    private const TABLE = 'tool_ltigroupautoenrol';

    /**
     * Set up test environment.
     *
     * Prepares the test environment by calling parent setup and resetting after test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Creates course configuration and group mappings for testing.
     *
     * This method sets up the necessary database records to configure
     * LTI group auto enrolment for a specific course and deployment.
     *
     * @param int $courseid The course ID to configure
     * @param string $deploymentid The LTI deployment ID
     * @param int[] $groupids Array of group IDs to map to this deployment
     * @param bool $enabled Whether the configuration should be enabled (default: true)
     * @return void
     */
    private function create_course_mapping(int $courseid, string $deploymentid, array $groupids, bool $enabled = true): void {
        global $DB;

        // Create base configuration record.
        $configrecord = (object)[
            'courseid'     => $courseid,
            'enable_enrol' => $enabled ? 1 : 0,
            'settings'     => json_encode([$deploymentid => $groupids]),
        ];
        $configrecord->id = $DB->insert_record(self::TABLE, $configrecord);

    }

    /**
     * Test that LTI enrolment adds users to mapped groups.
     *
     * Verifies that when a user is enrolled via LTI with a specific deployment ID,
     * they are automatically added to all groups mapped to that deployment.
     *
     * @return void
     * @covers \tool_ltigroupautoenrol\observer::user_enrolment_created
     */
    public function test_lti_enrolment_adds_user_to_mapped_groups(): void {
        global $DB;

        // Set up test data.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();

        // Create test groups.
        $group1 = groups_create_group((object)['courseid' => $course->id, 'name' => 'LTI Group A']);
        $group2 = groups_create_group((object)['courseid' => $course->id, 'name' => 'LTI Group B']);

        // Set up deployment mapping.
        $deploymentid = 'deployment-123';
        $this->create_course_mapping($course->id, $deploymentid, [$group1, $group2], true);

        // Trigger LTI enrolment event.
        $event = user_enrolment_created::create([
            'context'        => \context_course::instance($course->id),
            'relateduserid'  => $user->id,
            'courseid'       => $course->id,
            'other'          => [
                'enrol'            => 'lti',
                'ltideploymentid'  => $deploymentid,
            ],
        ]);
        $event->trigger();

        // Assert user is added to both mapped groups.
        $this->assertTrue(groups_is_member($group1, $user->id),
            'User should be added to first mapped group');
        $this->assertTrue(groups_is_member($group2, $user->id),
            'User should be added to second mapped group');
    }

    /**
     * Test that non-LTI enrolments are ignored.
     *
     * Verifies that enrolment events from other enrolment methods (e.g., manual)
     * do not trigger automatic group assignment.
     *
     * @return void
     * @covers \tool_ltigroupautoenrol\observer::user_enrolment_created
     */
    public function test_non_lti_enrolment_is_ignored(): void {
        // Set up test data.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();

        // Create test group.
        $group = groups_create_group((object)['courseid' => $course->id, 'name' => 'Test Group']);

        // Set up deployment mapping.
        $deploymentid = 'deployment-xyz';
        $this->create_course_mapping($course->id, $deploymentid, [$group], true);

        // Trigger non-LTI enrolment event (manual enrolment).
        $event = user_enrolment_created::create([
            'context'        => \context_course::instance($course->id),
            'relateduserid'  => $user->id,
            'courseid'       => $course->id,
            'other'          => [
                'enrol' => 'manual',  // Non-LTI enrolment method.
            ],
        ]);
        $event->trigger();

        // Assert user is not added to group.
        $this->assertFalse(groups_is_member($group, $user->id),
            'User should not be added to groups for non-LTI enrolments');
    }

    /**
     * Test that deleted groups are handled gracefully.
     *
     * Verifies that when mapped groups are deleted, the plugin handles this
     * gracefully without errors and only adds users to existing groups.
     *
     * @return void
     * @covers \tool_ltigroupautoenrol\observer::user_enrolment_created
     */
    public function test_deleted_groups_are_ignored(): void {
        // Set up test data.
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();

        // Create test groups.
        $existinggroup = groups_create_group((object)['courseid' => $course->id, 'name' => 'Existing Group']);
        $tobedeleted = groups_create_group((object)['courseid' => $course->id, 'name' => 'Group to Delete']);

        // Set up deployment mapping with both groups.
        $deploymentid = 'deployment-abc';
        $this->create_course_mapping($course->id, $deploymentid, [$existinggroup, $tobedeleted], true);

        // Delete one of the mapped groups.
        groups_delete_group($tobedeleted);

        // Trigger LTI enrolment event.
        $event = user_enrolment_created::create([
            'context'        => \context_course::instance($course->id),
            'relateduserid'  => $user->id,
            'courseid'       => $course->id,
            'other'          => [
                'enrol'            => 'lti',
                'ltideploymentid'  => $deploymentid,
            ],
        ]);
        $event->trigger();

        // Assert user is added to existing group but not the deleted one.
        $this->assertTrue(groups_is_member($existinggroup, $user->id),
            'User should be added to existing mapped groups');
        $this->assertFalse(groups_is_member($tobedeleted, $user->id),
            'User should not be added to deleted groups');
    }
}
