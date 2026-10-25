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
use tool_ltigroupautoenrol\local\course_config;
use tool_ltigroupautoenrol\local\mapping;

/**
 * Contract of the additive group assignment (no automatic removal by the plugin).
 *
 * The plugin only ever adds memberships. Changing the mapping, disabling the feature or deleting an
 * LTI tool does not remove memberships it created. Moodle core removes the group memberships of a
 * user only when the user loses their last enrolment in the course.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\local\assignment_service
 * @covers     \tool_ltigroupautoenrol\observer
 */
final class additive_semantics_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $tool;

    /** @var int */
    private $groupa;

    /** @var int */
    private $groupb;

    /** @var \stdClass */
    private $user;

    /** @var \tool_ltigroupautoenrol_generator */
    private $plugingen;

    /**
     * A user enrolled through an LTI tool mapped to group A.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/group/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $this->course = $gen->create_course();
        $this->tool = $this->plugingen->create_lti_tool($this->course->id);
        $this->groupa = (int) $gen->create_group(['courseid' => $this->course->id])->id;
        $this->groupb = (int) $gen->create_group(['courseid' => $this->course->id])->id;
        $this->plugingen->create_config($this->course->id, [$this->tool->id => [$this->groupa]]);
        $this->user = $gen->create_user();
        $this->plugingen->enrol_via_lti($this->tool, $this->user->id);
        $this->assertTrue(groups_is_member($this->groupa, $this->user->id));
    }

    /**
     * A changed mapping does not move existing members: they stay in the old group.
     */
    public function test_mapping_change_keeps_memberships(): void {
        config_repository::save(new course_config(
            (int) $this->course->id,
            true,
            new mapping([(int) $this->tool->id => [$this->groupb]])
        ));
        $this->assertTrue(groups_is_member($this->groupa, $this->user->id));
        $this->assertFalse(groups_is_member($this->groupb, $this->user->id));
    }

    /**
     * Disabling the feature keeps all memberships.
     */
    public function test_disabling_keeps_memberships(): void {
        config_repository::save(new course_config(
            (int) $this->course->id,
            false,
            new mapping([(int) $this->tool->id => [$this->groupa]])
        ));
        $this->assertTrue(groups_is_member($this->groupa, $this->user->id));
    }

    /**
     * A user with another enrolment keeps the membership when the LTI enrolment is removed;
     * without another enrolment, Moodle core removes it together with the last enrolment.
     */
    public function test_lti_unenrolment(): void {
        $gen = $this->getDataGenerator();
        $other = $gen->create_user();
        $this->plugingen->enrol_via_lti($this->tool, $other->id);
        $gen->enrol_user($other->id, $this->course->id, 'student', 'manual');
        $this->assertTrue(groups_is_member($this->groupa, $other->id));

        $plugin = enrol_get_plugin('lti');
        $instance = $this->get_lti_instance();
        $plugin->unenrol_user($instance, $other->id);
        $plugin->unenrol_user($instance, $this->user->id);

        $this->assertTrue(groups_is_member($this->groupa, $other->id), 'another enrolment: membership is kept');
        $this->assertFalse(groups_is_member($this->groupa, $this->user->id), 'last enrolment: core removes membership');
    }

    /**
     * Deleting the LTI tool removes it from the mapping; memberships of users with another enrolment stay.
     */
    public function test_tool_deletion(): void {
        $gen = $this->getDataGenerator();
        $gen->enrol_user($this->user->id, $this->course->id, 'student', 'manual');
        enrol_get_plugin('lti')->delete_instance($this->get_lti_instance());

        $this->assertTrue(config_repository::get($this->course->id)->mapping->is_empty());
        $this->assertTrue(groups_is_member($this->groupa, $this->user->id));
    }

    /**
     * Returns the enrol_lti instance of the tool.
     *
     * @return \stdClass
     */
    private function get_lti_instance(): \stdClass {
        global $DB;
        return $DB->get_record('enrol', ['id' => $this->tool->enrolid], '*', MUST_EXIST);
    }
}
