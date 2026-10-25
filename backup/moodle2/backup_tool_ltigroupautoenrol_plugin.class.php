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

defined('MOODLE_INTERNAL') || die();

// Core does not load the base class of admin tool backup plugins (unlike local plugins).
global $CFG;
require_once($CFG->dirroot . '/backup/moodle2/backup_tool_plugin.class.php');

/**
 * Course backup of the LTI tool to group mapping.
 *
 * Only the configuration is backed up (enabled flag and mapping with the tool and group ids of the
 * source course); it holds no user data. Restore maps the ids to the restored LTI tools and groups,
 * see restore_tool_ltigroupautoenrol_plugin.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_tool_ltigroupautoenrol_plugin extends backup_tool_plugin {
    /**
     * Adds the configuration of the course to the course backup.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $config = new backup_nested_element('config', ['id'], ['enable_enrol', 'settings']);
        $plugin->add_child($wrapper);
        $wrapper->add_child($config);
        $config->set_source_table('tool_ltigroupautoenrol', ['courseid' => backup::VAR_COURSEID]);
        return $plugin;
    }
}
