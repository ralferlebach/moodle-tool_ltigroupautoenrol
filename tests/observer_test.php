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
 * Unit tests for event handling in LTI group auto enrolment tool.
 *
 * This test class exercises the private check_and_enrol() helper of the
 * observer class via reflection, verifying that users are correctly added
 * to mapped groups upon LTI enrolment.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach <ralf.erlebach@gmx.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ltigroupautoenrol;

use advanced_testcase;

/**
 * Test class for LTI group auto enrolment event observer.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\observer
 */
final class observer_test extends advanced_testcase {

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
    }

    /**
     * Invokes the private observer helper with reflection.
     *
     * The public observer entrypoint relies on a real enrolment snapshot and on
     * enrol_lti\helper::get_lti_tools(). For a focused unit test, we exercise the
     * actual group-assignment logic directly.
     *
     * @param \stdClass $config        Plugin configuration for the course.
     * @param \stdClass $ltiinformation LTI tool information object.
     * @param \stdClass $enroldata     Enrolment data containing the user id.
     * @return void
     */
    private function invoke_check_and_enrol(
        \stdClass $config,
        \stdClass $ltiinformation,
        \stdClass $enroldata
    ): void {
        $method = new \ReflectionMethod(observer::class, 'check_and_enrol');
        $method->setAccessible(true);
        $method->invoke(null, $config, $ltiinformation, $enroldata);
    }

    /**
     * Creates a plugin configuration object for a course.
     *
     * @param int   $courseid  The course id.
     * @param int   $ltitoolid The LTI tool id.
     * @param int[] $groupids  Array of group ids to map.
     * @param bool  $enabled   Whether auto-enrolment is enabled.
     * @return \stdClass The configuration object.
     */
    private function create_course_mapping(
        int $courseid,
        int $ltitoolid,
        array $groupids,
        bool $enabled = true
    ): \stdClass {
        return (object) [
            'courseid' => $courseid,
            'enable_enrol' => $enabled ? 1 : 0,
            'settings' => json_encode([$ltitoolid => $groupids]),
        ];
    }

    /**
     * Tests that LTI enrolment adds users to mapped groups.
     *
     * @covers \tool_ltigroupautoenrol\observer::check_and_enrol
     * @return void
     */
    public function test_lti_enrolment_adds_user_to_mapped_groups(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $group1 = groups_create_group((object) ['courseid' => $course->id, 'name' => 'LTI Group A']);
        $group2 = groups_create_group((object) ['courseid' => $course->id, 'name' => 'LTI Group B']);

        $ltitoolid = 12345;
        $config = $this->create_course_mapping($course->id, $ltitoolid, [$group1, $group2], true);
        $ltiinformation = (object) ['id' => $ltitoolid, 'courseid' => $course->id];
        $enroldata = (object) ['userid' => $user->id];

        $this->invoke_check_and_enrol($config, $ltiinformation, $enroldata);

        $this->assertTrue(groups_is_member($group1, $user->id));
        $this->assertTrue(groups_is_member($group2, $user->id));
    }

    /**
     * Tests that non-mapped LTI tools are ignored.
     *
     * @covers \tool_ltigroupautoenrol\observer::check_and_enrol
     * @return void
     */
    public function test_non_lti_enrolment_is_ignored(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $group = groups_create_group((object) ['courseid' => $course->id, 'name' => 'Test Group']);

        $config = $this->create_course_mapping($course->id, 12345, [$group], true);
        $ltiinformation = (object) ['id' => 54321, 'courseid' => $course->id];
        $enroldata = (object) ['userid' => $user->id];

        $this->invoke_check_and_enrol($config, $ltiinformation, $enroldata);

        $this->assertFalse(groups_is_member($group, $user->id));
    }

    /**
     * Tests that deleted groups are handled gracefully.
     *
     * @covers \tool_ltigroupautoenrol\observer::check_and_enrol
     * @return void
     */
    public function test_deleted_groups_are_ignored(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $existinggroup = groups_create_group((object) ['courseid' => $course->id, 'name' => 'Existing Group']);
        $tobedeleted = groups_create_group((object) ['courseid' => $course->id, 'name' => 'Group to Delete']);

        $ltitoolid = 67890;
        $config = $this->create_course_mapping($course->id, $ltitoolid, [$existinggroup, $tobedeleted], true);
        $ltiinformation = (object) ['id' => $ltitoolid, 'courseid' => $course->id];
        $enroldata = (object) ['userid' => $user->id];

        groups_delete_group($tobedeleted);

        $this->invoke_check_and_enrol($config, $ltiinformation, $enroldata);

        $this->assertTrue(groups_is_member($existinggroup, $user->id));
        $this->assertFalse(groups_is_member($tobedeleted, $user->id));
    }
}
