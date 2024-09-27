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
 * Event observers used in tool_ltigroupautoenrol.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2024 ralferlebach, based upon tool_groupautoenrol
 * @author     Ralf Erlebach, https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\event\user_enrolment_created;

/**
 * Event observer for tool_ltigroupautoenrol.
 */
class tool_ltigroupautoenrol_observer {

    /**
     * Triggered via core\event\user_enrolment_created (user_enrolled)
     * Action when user is enrolled
     *
     * @param user_enrolment_created $event
     *
     * @return bool true if all ok
     * @throws coding_exception
     * @throws dml_exception
     */
    public static function user_is_enrolled(user_enrolment_created $event): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');

        // Test, if the course has ltigroupautoenrol enabled.
        $ltigroupautoenrol = $DB->get_record('tool_ltigroupautoenrol', ['courseid' => $event->courseid]);

        if (empty($ltigroupautoenrol->enable_enrol)) {
            return true;
        }

        $enroldata = $event->get_record_snapshot($event->objecttable, $event->objectid);

        // Test, if enrolment was done by LTI.
        $ltiinformation = \enrol_lti\helper::get_lti_tools(['courseid' => $event->courseid,
        'enrolid' => $enroldata->enrolid,
        'ltiversion' => 'LTI-1p3']);

        if (empty($ltiinformation)) {
            return true;
        } else {
            $ltiinformation = $ltiinformation[array_key_first($ltiinformation)];
        }

        self::check_and_enrol($ltigroupautoenrol, $ltiinformation, $enroldata);

        return true;
    }

    /**
     * Check groups and add enrol user.
     *
     * @param stdClass $ltigroupautoenrol
     * @param stdClass $ltiinformation
     *
     * @throws coding_exception
     */
    private static function check_and_enrol(stdClass $ltigroupautoenrol, stdClass $ltiinformation, stdClass $enroldata): void {
        global $USER;

        $allgroupscourse = groups_get_all_groups($ltiinformation->courseid);

        $groupstoenroll = json_decode($ltigroupautoenrol->settings, true);

        if (empty($groupstoenroll)) {
            return;
        }

        foreach ($groupstoenroll[$ltiinformation->id] as $group) {
            if (array_key_exists($group, $allgroupscourse)) {
                groups_add_member($group, $enroldata->userid);
                error_log("Enrol user ".$enroldata->userid." to group ".$group,0);
            }
        }
    }
}
