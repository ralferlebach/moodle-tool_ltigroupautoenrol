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

/**
 * Tests of the course navigation extension.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::tool_ltigroupautoenrol_extend_navigation_course
 */
final class lib_test extends \advanced_testcase {
    /**
     * Loads lib.php.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/admin/tool/ltigroupautoenrol/lib.php');
    }

    /**
     * Builds a course navigation root, optionally with the "users" node.
     *
     * @param bool $withusers
     * @return \navigation_node
     */
    private function navigation(bool $withusers): \navigation_node {
        $root = new \navigation_node(['text' => 'root', 'key' => 'root']);
        if ($withusers) {
            $root->add('Users', null, \navigation_node::TYPE_CONTAINER, null, 'users');
        }
        return $root;
    }

    /**
     * Returns the plugin node if it was added.
     *
     * @param \navigation_node $root
     * @return \navigation_node|false
     */
    private function plugin_node(\navigation_node $root) {
        $users = $root->get('users');
        return $users ? $users->get('tool_ltigroupautoenrol') : false;
    }

    /**
     * Editing teachers get the link in the "users" node of their course, pointing to that course.
     */
    public function test_link_for_editing_teacher(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $root = $this->navigation(true);

        tool_ltigroupautoenrol_extend_navigation_course($root, $course, \context_course::instance($course->id));

        $node = $this->plugin_node($root);
        $this->assertNotFalse($node);
        $this->assertSame((string) $course->id, $node->action->get_param('id'));
    }

    /**
     * No link without the capability, for other contexts or courses, or on the site course.
     */
    public function test_no_link_where_not_applicable(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));
        $root = $this->navigation(true);
        tool_ltigroupautoenrol_extend_navigation_course($root, $course, \context_course::instance($course->id));
        $this->assertFalse($this->plugin_node($root), 'non-editing teacher');

        $this->setAdminUser();
        $root = $this->navigation(true);
        tool_ltigroupautoenrol_extend_navigation_course($root, $course, \context_module::instance($module->cmid));
        $this->assertFalse($this->plugin_node($root), 'module context');

        $root = $this->navigation(true);
        tool_ltigroupautoenrol_extend_navigation_course($root, $course, \context_course::instance($other->id));
        $this->assertFalse($this->plugin_node($root), 'context of another course');

        $root = $this->navigation(true);
        tool_ltigroupautoenrol_extend_navigation_course($root, get_site(), \context_course::instance(SITEID));
        $this->assertFalse($this->plugin_node($root), 'site course');
    }

    /**
     * A missing "users" node does not cause an error.
     */
    public function test_missing_users_node(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $root = $this->navigation(false);

        tool_ltigroupautoenrol_extend_navigation_course($root, $course, \context_course::instance($course->id));

        $this->assertFalse($root->get('users'));
    }
}
