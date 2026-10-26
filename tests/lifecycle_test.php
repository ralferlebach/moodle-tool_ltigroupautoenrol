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

/**
 * Uninstall/reinstall contract: plugin data goes, group memberships stay.
 *
 * @package    tool_ltigroupautoenrol
 * @category   test
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lifecycle_test extends \advanced_testcase {
    /**
     * Reinstalls the plugin the way the installer does (schema, capabilities, version).
     *
     * @return void
     */
    private function reinstall(): void {
        global $CFG, $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('tool_ltigroupautoenrol')) {
            $dbman->install_from_xmldb_file($CFG->dirroot . '/admin/tool/ltigroupautoenrol/db/install.xml');
        }
        update_capabilities('tool_ltigroupautoenrol');
        $plugin = new \stdClass();
        require($CFG->dirroot . '/admin/tool/ltigroupautoenrol/version.php');
        set_config('version', $plugin->version, 'tool_ltigroupautoenrol');
        \cache_helper::purge_all();
    }

    /**
     * Restores the installed plugin whatever the test did.
     *
     * @return void
     */
    protected function tearDown(): void {
        $this->reinstall();
        parent::tearDown();
    }

    /**
     * Uninstall removes table and capability, keeps memberships; reinstall starts clean and works again.
     */
    public function test_uninstall_and_reinstall(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('tool_ltigroupautoenrol');
        $course = $gen->create_course();
        $tool = $plugingen->create_lti_tool($course->id);
        $group = $gen->create_group(['courseid' => $course->id])->id;
        $plugingen->create_config($course->id, [$tool->id => [$group]]);
        $before = $gen->create_user();
        $plugingen->enrol_via_lti($tool, $before->id);
        $this->assertTrue(groups_is_member($group, $before->id));

        ob_start();
        uninstall_plugin('tool', 'ltigroupautoenrol');
        ob_end_clean();

        $this->assertFalse($DB->get_manager()->table_exists('tool_ltigroupautoenrol'));
        $this->assertFalse($DB->record_exists('capabilities', ['name' => 'tool/ltigroupautoenrol:manage']));
        $this->assertTrue(groups_is_member($group, $before->id), 'memberships survive the uninstall');

        $this->reinstall();

        $this->assertNull(config_repository::get($course->id), 'reinstall starts without configuration');
        $this->assertTrue($DB->record_exists('capabilities', ['name' => 'tool/ltigroupautoenrol:manage']));
        $plugingen->create_config($course->id, [$tool->id => [$group]]);
        $after = $gen->create_user();
        $plugingen->enrol_via_lti($tool, $after->id);
        $this->assertTrue(groups_is_member($group, $after->id), 'plugin works again after reinstall');
        $this->assertTrue(groups_is_member($group, $before->id));
    }
}
