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
        $restored = $this->translate($source, $courseid);
        if (!$restored->is_empty()) {
            $this->store($courseid, $restored);
        }
    }

    /**
     * Translates the tool and group ids of the backup to the restored ones.
     *
     * Tool ids come from the restore mapping of enrol_lti; each must also belong to an LTI enrolment instance of
     * the restored course (see tool_belongs_to_course()), so no tool of another course or without an instance
     * is stored. Groups must have been restored into the course.
     *
     * @param mapping $source Mapping of the backup.
     * @param int $courseid Restored course.
     * @return mapping
     */
    private function translate(mapping $source, int $courseid): mapping {
        $coursegroups = groups_get_all_groups($courseid);
        $translated = [];
        foreach ($source->to_array() as $oldtoolid => $oldgroupids) {
            $toolid = (int) $this->get_mappingid('enrol_lti_tool', $oldtoolid);
            if (!$toolid || !$this->tool_belongs_to_course($toolid, $courseid)) {
                continue;
            }
            foreach ($oldgroupids as $oldgroupid) {
                $groupid = (int) $this->get_mappingid('group', $oldgroupid);
                if ($groupid && isset($coursegroups[$groupid])) {
                    $translated[$toolid][] = $groupid;
                }
            }
        }
        return new mapping($translated);
    }

    /**
     * Stores the restored mapping; a course with its own configuration keeps its on/off flag and gets the pairs added.
     *
     * @param int $courseid
     * @param mapping $restored Not empty.
     * @return void
     */
    private function store(int $courseid, mapping $restored): void {
        try {
            $existing = config_repository::get($courseid);
        } catch (invalid_mapping_exception $e) {
            $existing = null;
        }
        if (!$existing) {
            config_repository::save(new course_config($courseid, !empty($this->restoredconfig->enable_enrol), $restored));
            return;
        }
        $merged = $existing->mapping->to_array();
        foreach ($restored->to_array() as $toolid => $groupids) {
            $merged[$toolid] = array_values(array_unique(array_merge($merged[$toolid] ?? [], $groupids)));
        }
        config_repository::save(new course_config($courseid, $existing->enabled, new mapping($merged)));
    }

    /**
     * Whether a restored LTI tool belongs to an LTI enrolment instance of the restored course.
     *
     * enrol_lti links its restored tools to the restored enrolment instances in its own after_restore method.
     * Depending on the order of the after_restore methods, the tool still carries the enrolment id of the backup
     * (translated through the restore mapping "enrol") or already the new one; both are accepted, but only if
     * the instance is an enrol_lti instance of this course. Tools whose instance was not restored (e.g. enrol_lti
     * disabled on the target site) are rejected.
     *
     * @param int $toolid
     * @param int $courseid
     * @return bool
     */
    protected function tool_belongs_to_course(int $toolid, int $courseid): bool {
        global $DB;
        $tool = $DB->get_record('enrol_lti_tools', ['id' => $toolid], 'id, enrolid');
        if (!$tool || empty($tool->enrolid)) {
            return false;
        }
        $enrolid = (int) $this->get_mappingid('enrol', $tool->enrolid) ?: (int) $tool->enrolid;
        return $DB->record_exists('enrol', ['id' => $enrolid, 'courseid' => $courseid, 'enrol' => 'lti']);
    }
}
