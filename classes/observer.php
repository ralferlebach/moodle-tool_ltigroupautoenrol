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
 * Event observers used in tool_groupautoenrol.
 *
 * @package    tool_groupautoenrol
 * @copyright  2016 Pascal
 * @author     Pascal M - https://github.com/pascal-my
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\event\user_enrolment_created;

/**
 * Event observer for tool_groupautoenrol.
 *
 * @package    tool_groupautoenrol
 * @copyright  2016 Pascal
 * @author     Pascal M - https://github.com/pascal-my
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        $enroldata = $event->get_record_snapshot($event->objecttable, $event->objectid);
        $groupautoenrol = $DB->get_record('tool_groupautoenrol', ['courseid' => $event->courseid]);

        if (empty($groupautoenrol->enable_enrol)) {
            return true;
        }

        $groupstouse = self::get_course_groups($groupautoenrol, $event);

        // Checking if there is at least 1 group.
        if (empty($groupstouse)) {
            return true;
        }

        // Checking if user is not already into these groups.
        if (self::user_is_group_member($groupstouse, $enroldata)) {
            return true;
        }

        self::add_user_to_group($groupautoenrol, $groupstouse, $enroldata);

        return true;

    }

    /**
     * Get the groups to use for the course.
     *
     * @param stdClass $groupautoenrol
     * @param user_enrolment_created $event
     *
     * @return array
     */
    private static function get_course_groups(stdClass $groupautoenrol, user_enrolment_created $event): array {
        $groupstouse = [];
        if (!empty($groupautoenrol->use_groupslist)) {

            // If use_groupslist == 1, we need to check.
            // A) if the list is not empty.
            if (!empty($groupautoenrol->groupslist)) {
                $groupstemp = explode(",", $groupautoenrol->groupslist);

                // B) if the listed groups still exists
                // (because when a group is deleted, groupautoenrol table is not updated !).
                $allgroupscourse = groups_get_all_groups($event->courseid);

                foreach ($groupstemp as $group) {

                    if (empty($allgroupscourse[$group])) {
                        continue;
                    }

                    $groupstouse[] = $allgroupscourse[$group];
                }
            }

        } else {
            // If use_groupslist == 0, use all groups course.
            $groupstouse = groups_get_all_groups($event->courseid);
        }

        return $groupstouse;
    }

    /**
     * Add user to group.
     *
     * @param stdClass $groupautoenrol
     * @param array $groupstouse
     * @param stdClass $enroldata
     *
     * @throws coding_exception
     */
    private static function add_user_to_group(stdClass $groupautoenrol, array $groupstouse, stdClass $enroldata): void {
        global $USER;

        if (!empty($groupautoenrol->enrol_method)) {
            // 0 = random, 1 = alpha, 2 = balanced.
            foreach ($groupstouse as $group) {
                $groupname = $group->name;

                if (($groupname[strlen($groupname) - 2] <= $USER->lastname[0])
                    && ($groupname[strlen($groupname) - 1] >= $USER->lastname[0])) {
                    groups_add_member($group->id, $enroldata->userid);
                    break; // Exit foreach (is it working ?).
                }
            }
        } else {
            // Array_rand return key not value!
            $randkeys = array_rand($groupstouse);
            $group2add = $groupstouse[$randkeys];
            groups_add_member($group2add, $enroldata->userid);
        }
    }

    /**
     * Check if user is already in one of the groups.
     *
     * @param array $groupstouse
     * @param stdClass $enroldata
     *
     * @return bool
     */
    private static function user_is_group_member(array $groupstouse, stdClass $enroldata): bool {

        foreach ($groupstouse as $group) {
            if (groups_is_member($group->id, $enroldata->userid)) {
                return true;
            }
        }

        return false;
    }

}
