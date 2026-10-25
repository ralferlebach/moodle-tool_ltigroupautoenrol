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

namespace tool_ltigroupautoenrol\form;

use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\lti_resolver;

/**
 * Server-side validation of the settings form and the capability contract.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_ltigroupautoenrol\form\manage_lti_group_auto_enrol_form
 */
final class manage_form_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $tool;

    /** @var int */
    private $group;

    /** @var \stdClass Tool of another course. */
    private $foreigntool;

    /** @var int Group of another course. */
    private $foreigngroup;

    /**
     * Creates the course, a tool, a group and the same in a second course.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $this->course = $gen->create_course();
        $this->tool = $plugingen->create_lti_tool($this->course->id);
        $this->group = $gen->create_group(['courseid' => $this->course->id])->id;
        $other = $gen->create_course();
        $this->foreigntool = $plugingen->create_lti_tool($other->id);
        $this->foreigngroup = $gen->create_group(['courseid' => $other->id])->id;
        $this->setAdminUser();
    }

    /**
     * Submits the form with the given raw values.
     *
     * @param array $submission
     * @return manage_lti_group_auto_enrol_form
     */
    private function submit(array $submission): manage_lti_group_auto_enrol_form {
        manage_lti_group_auto_enrol_form::mock_submit($submission);
        return new manage_lti_group_auto_enrol_form(null, [
            'course' => $this->course,
            'tools' => lti_resolver::get_course_tools($this->course->id),
            'groups' => groups_get_all_groups($this->course->id),
            'config' => config_repository::get_or_default($this->course->id),
        ]);
    }

    /**
     * A legitimate submission produces the mapping of the course.
     */
    public function test_valid_submission(): void {
        $form = $this->submit(['enable_enrol' => 1, 'groups_' . $this->tool->id => [$this->group]]);
        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertSame([(int) $this->tool->id => [(int) $this->group]], $form->get_mapping($data)->to_array());
    }

    /**
     * A group of another course is rejected with a validation error, not silently stored.
     */
    public function test_foreign_group_is_rejected(): void {
        $form = $this->submit(['enable_enrol' => 1, 'groups_' . $this->tool->id => [$this->group, $this->foreigngroup]]);
        $this->assertNull($form->get_data());
        // Validation inspects the raw submission, not the (already filtered) exported data.
        $this->assertArrayHasKey('groups_' . $this->tool->id, $form->validation([], []));
    }

    /**
     * An empty multi-select (core submits a placeholder) is valid: a tool can be left unmapped or cleared.
     */
    public function test_empty_multiselect_is_accepted(): void {
        $othertool = $this->getDataGenerator()->get_plugin_generator('tool_ltigroupautoenrol')
            ->create_lti_tool($this->course->id);
        $form = $this->submit([
            'enable_enrol' => 1,
            'groups_' . $this->tool->id => [$this->group],
            'groups_' . $othertool->id => '_qf__force_multiselect_submission',
        ]);
        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertSame([(int) $this->tool->id => [(int) $this->group]], $form->get_mapping($data)->to_array());
    }

    /**
     * A single scalar id (manipulated request instead of a list) is rejected.
     */
    public function test_scalar_group_is_rejected(): void {
        $form = $this->submit(['enable_enrol' => 1, 'groups_' . $this->tool->id => $this->foreigngroup]);
        $this->assertNull($form->get_data());
    }

    /**
     * A tool deleted after the form was rendered: a stale submission for it is rejected, and its mapping
     * is dropped on the next save.
     */
    public function test_deleted_tool_is_rejected_and_dropped(): void {
        global $DB;
        $toolid = (int) $this->tool->id;
        config_repository::save(new \tool_ltigroupautoenrol\local\course_config(
            $this->course->id,
            true,
            new \tool_ltigroupautoenrol\local\mapping([$toolid => [(int) $this->group]])
        ));
        $instance = $DB->get_record('enrol', ['id' => $this->tool->enrolid], '*', MUST_EXIST);
        enrol_get_plugin('lti')->delete_instance($instance);

        // A stale form still submitting the select of the deleted tool is rejected; reloading shows the current tools.
        $form = $this->submit(['enable_enrol' => 1, 'groups_' . $toolid => [$this->group]]);
        $this->assertNull($form->get_data());

        $form = $this->submit(['enable_enrol' => 1]);
        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertTrue($form->get_mapping($data)->is_empty());
    }

    /**
     * Non-numeric values are rejected.
     */
    public function test_non_numeric_group_is_rejected(): void {
        $form = $this->submit(['enable_enrol' => 1, 'groups_' . $this->tool->id => ['1 OR 1=1']]);
        $this->assertNull($form->get_data());
    }

    /**
     * A group select of a tool of another course (or of a deleted or invented tool) is rejected with a form error,
     * not silently dropped.
     *
     * @dataProvider unknown_tool_provider
     * @param string $which "foreign", "deleted" or "invented".
     */
    public function test_unknown_tool_is_rejected(string $which): void {
        $toolid = [
            'foreign' => $this->foreigntool->id,
            'deleted' => $this->foreigntool->id + 1000,
            'invented' => 999999,
        ][$which];
        $form = $this->submit([
            'enable_enrol' => 1,
            'groups_' . $this->tool->id => [$this->group],
            'groups_' . $toolid => [$this->group],
        ]);
        $this->assertNull($form->get_data());
        $this->assertSame(
            get_string('error_unknowntool', 'tool_ltigroupautoenrol'),
            $form->validation(['enable_enrol' => 1], [])['enable_enrol']
        );
    }

    /**
     * Unknown tools for {@see test_unknown_tool_is_rejected()}.
     *
     * @return array
     */
    public static function unknown_tool_provider(): array {
        return [
            'tool of another course' => ['foreign'],
            'deleted tool' => ['deleted'],
            'invented tool id' => ['invented'],
        ];
    }

    /**
     * The former hidden fields (ltitoolid_*, ltitoolcount, groupslist_*) are gone and have no effect.
     */
    public function test_legacy_fields_are_ignored(): void {
        $form = $this->submit([
            'enable_enrol' => 1,
            'ltitoolcount' => 5,
            'ltitoolid_0' => $this->foreigntool->id,
            'groupslist_0' => [$this->foreigngroup],
        ]);
        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertTrue($form->get_mapping($data)->is_empty());
    }

    /**
     * Capability contract: editing teachers and managers may configure, non-editing teachers and students may not.
     */
    public function test_capability_defaults(): void {
        $gen = $this->getDataGenerator();
        $context = \context_course::instance($this->course->id);
        $expected = ['editingteacher' => true, 'manager' => true, 'teacher' => false, 'student' => false];
        foreach ($expected as $role => $allowed) {
            $user = $gen->create_user();
            $gen->enrol_user($user->id, $this->course->id, $role);
            $this->assertSame($allowed, has_capability('tool/ltigroupautoenrol:manage', $context, $user), $role);
        }
    }

    /**
     * Configured feature, then all groups deleted: the switch and the save button remain, so it can be switched off.
     */
    public function test_switch_off_without_groups(): void {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        $this->getDataGenerator()->get_plugin_generator('tool_ltigroupautoenrol')
            ->create_config($this->course->id, [$this->tool->id => [$this->group]]);
        groups_delete_group($this->group);
        $this->assertSame([], groups_get_all_groups($this->course->id));

        manage_lti_group_auto_enrol_form::mock_submit(['enable_enrol' => 0, 'submitbutton' => 'Save']);
        $form = new manage_lti_group_auto_enrol_form(null, [
            'course' => $this->course,
            'tools' => lti_resolver::get_course_tools($this->course->id),
            'groups' => [],
            'config' => config_repository::get_or_default($this->course->id),
        ]);
        ob_start();
        $form->display();
        $html = ob_get_clean();
        $this->assertStringContainsString('name="enable_enrol"', $html);
        $this->assertStringContainsString('name="submitbutton"', $html);
        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertSame('0', (string) $data->enable_enrol);
    }
}
