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
 * @copyright  2026 ralferlebach
 * @author     Ralf Erlebach, https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_ltigroupautoenrol;

use core\event\course_deleted;
use core\event\enrol_instance_deleted;
use core\event\group_deleted;
use core\event\user_enrolment_created;
use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\invalid_mapping_exception;

/**
 * Event observer for tool_ltigroupautoenrol.
 */
class observer {
    /**
     * Assigns a newly LTI-enrolled user to the groups mapped to the LTI tool.
     *
     * Triggered via core\event\user_enrolment_created. Never throws for plugin-internal
     * problems, so an enrolment can not fail because of this plugin.
     *
     * @param user_enrolment_created $event
     * @return bool Always true.
     */
    public static function user_is_enrolled(user_enrolment_created $event): bool {
        if (($event->other['enrol'] ?? null) !== 'lti') {
            // Only enrol_lti enrolments are relevant; avoids any lookup for other methods.
            return true;
        }
        $userenrolment = $event->get_record_snapshot($event->objecttable, $event->objectid);
        if (!$userenrolment) {
            return true;
        }
        try {
            assignment_service::handle_new_enrolment((int) $event->courseid, $userenrolment);
        } catch (invalid_mapping_exception $e) {
            // Corrupt configuration is treated as "no mapping". No user data in the message.
            debugging(
                'tool_ltigroupautoenrol: invalid mapping in course ' . $event->courseid . ': ' . $e->debuginfo,
                DEBUG_DEVELOPER
            );
        }
        return true;
    }

    /**
     * Removes the plugin configuration of a deleted course.
     *
     * @param course_deleted $event
     * @return bool Always true.
     */
    public static function course_deleted(course_deleted $event): bool {
        config_repository::delete_for_course((int) $event->objectid);
        return true;
    }

    /**
     * Removes mappings of LTI tools whose enrol instance was deleted.
     *
     * @param enrol_instance_deleted $event
     * @return bool Always true.
     */
    public static function enrol_instance_deleted(enrol_instance_deleted $event): bool {
        if (($event->other['enrol'] ?? null) === 'lti') {
            config_repository::prune((int) $event->courseid);
        }
        return true;
    }

    /**
     * Removes a deleted group from the mapping. Existing memberships are handled by core.
     *
     * @param group_deleted $event
     * @return bool Always true.
     */
    public static function group_deleted(group_deleted $event): bool {
        config_repository::prune((int) $event->courseid);
        return true;
    }
}
