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

use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\course_config;
use tool_ltigroupautoenrol\local\invalid_mapping_exception;
use tool_ltigroupautoenrol\local\mapping;

defined('MOODLE_INTERNAL') || die();

// Core does not load the base class of admin tool backup plugins (unlike local plugins).
global $CFG;
require_once($CFG->dirroot . '/backup/moodle2/restore_tool_plugin.class.php');

/**
 * Course restore (also course copy and duplicate) of the LTI tool to group mapping.
 *
 * The ids in the backup belong to the source course. They are translated after the whole restore,
 * when the LTI tools (mapping "enrol_lti_tool" of enrol_lti) and the groups (mapping "group") exist:
 * - tools or groups that were not restored (e.g. enrolment methods or groups not included) are dropped,
 * - nothing is stored if no tool to group pair remains,
 * - a target course with its own configuration keeps its enabled flag and gets the restored pairs added.
 * Like the other course settings, core restores this part only into a new course or with "overwrite course
 * configuration" (overwrite_conf); course duplication includes the LTI tools only together with users.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_tool_ltigroupautoenrol_plugin extends restore_tool_plugin {
    /** @var \stdClass|null Configuration read from the backup, translated in after_restore_course(). */
    protected $restoredconfig = null;

    /**
     * Paths of the course part of the backup.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        return [new restore_path_element('tool_ltigroupautoenrol_config', $this->get_pathfor('/config'))];
    }

    /**
     * Keeps the configuration until all ids can be translated.
     *
     * @param array|\stdClass $data
     * @return void
     */
    public function process_tool_ltigroupautoenrol_config($data) {
        $this->restoredconfig = (object) $data;
    }

    /**
     * Translates tool and group ids and stores the configuration of the restored course.
     *
     * @return void
     */
    public function after_restore_course() {
        global $DB;
        if (!$this->restoredconfig) {
            return;
        }
        $courseid = (int) $this->task->get_courseid();
        try {
            $source = mapping::from_json((string) ($this->restoredconfig->settings ?? ''));
        } catch (invalid_mapping_exception $e) {
            // A corrupt mapping in the backup is not restored.
            return;
        }
        // Tool ids come from the restore mapping of enrol_lti, so they are tools of this restore. Their link to the
        // restored enrolment instance is only set by enrol_lti's own after_restore, which may run after this method,
        // so the tools are not looked up through the course here.
        $coursegroups = groups_get_all_groups($courseid);
        $translated = [];
        foreach ($source->to_array() as $oldtoolid => $oldgroupids) {
            $toolid = (int) $this->get_mappingid('enrol_lti_tool', $oldtoolid);
            if (!$toolid || !$DB->record_exists('enrol_lti_tools', ['id' => $toolid])) {
                continue;
            }
            foreach ($oldgroupids as $oldgroupid) {
                $groupid = (int) $this->get_mappingid('group', $oldgroupid);
                if ($groupid && isset($coursegroups[$groupid])) {
                    $translated[$toolid][] = $groupid;
                }
            }
        }
        $restored = new mapping($translated);
        if ($restored->is_empty()) {
            return;
        }
        try {
            $existing = config_repository::get($courseid);
        } catch (invalid_mapping_exception $e) {
            $existing = null;
        }
        if ($existing) {
            $merged = $existing->mapping->to_array();
            foreach ($restored->to_array() as $toolid => $groupids) {
                $merged[$toolid] = array_values(array_unique(array_merge($merged[$toolid] ?? [], $groupids)));
            }
            config_repository::save(new course_config($courseid, $existing->enabled, new mapping($merged)));
            return;
        }
        config_repository::save(new course_config($courseid, !empty($this->restoredconfig->enable_enrol), $restored));
    }
}
