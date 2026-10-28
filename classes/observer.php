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
use tool_ltigroupautoenrol\local\assignment_result;
use tool_ltigroupautoenrol\local\assignment_service;
use tool_ltigroupautoenrol\event\group_assignment_base;
use tool_ltigroupautoenrol\local\config_repository;
use tool_ltigroupautoenrol\local\event_reporter;
use tool_ltigroupautoenrol\local\invalid_mapping_exception;

/**
 * Event observer for tool_ltigroupautoenrol.
 */
class observer {
    /**
     * Assigns a newly LTI-enrolled user to the groups mapped to the LTI tool.
     *
     * Triggered via core\event\user_enrolment_created, after the enrolment is complete. This method is an error
     * boundary: it never throws, so the enrolment can not fail because of this plugin. Core only catches
     * exceptions of observers, not PHP errors (TypeError and the like), and reports them only as developer
     * debugging. Here every failure is counted for the course (warning on the settings page until a backfill
     * has verified that nothing is missing; the backfill also adds the missing memberships), logged with
     * course id and exception class only and reported as event group_assignment_failed.
     *
     * @param user_enrolment_created $event
     * @return bool Always true.
     */
    public static function user_is_enrolled(user_enrolment_created $event): bool {
        if (($event->other['enrol'] ?? null) !== 'lti') {
            // Only enrol_lti enrolments are relevant; avoids any lookup for other methods.
            return true;
        }
        $courseid = (int) $event->courseid;
        $userenrolment = null;
        try {
            $userenrolment = $event->get_record_snapshot($event->objecttable, $event->objectid);
            if (!$userenrolment) {
                return true;
            }
            static::report($courseid, static::assign($courseid, $userenrolment));
        } catch (invalid_mapping_exception $e) {
            // Corrupt configuration is treated as "no mapping". No user data in the message.
            debugging('tool_ltigroupautoenrol: invalid mapping in course ' . $courseid . ': ' . $e->debuginfo, DEBUG_DEVELOPER);
        } catch (\Throwable $e) {
            static::report_failure($courseid, $e, $userenrolment ?: null);
        }
        return true;
    }

    /**
     * Assigns the user of a new enrolment (separate method so tests can simulate failures).
     *
     * @param int $courseid
     * @param \stdClass $userenrolment user_enrolments record.
     * @return assignment_result|null
     */
    protected static function assign(int $courseid, \stdClass $userenrolment): ?assignment_result {
        return assignment_service::handle_new_enrolment($courseid, $userenrolment);
    }

    /**
     * Records and logs an unexpected failure of the event path (course id and exception class only).
     *
     * The number of affected memberships is unknown, so one failure is counted. If even that can not be
     * stored (e.g. the database is unavailable), the log line says so. If the user enrolment is known, the
     * event group_assignment_failed is triggered (tool id 0: the failure may have happened before the tool
     * was resolved; code: basename of the exception class).
     *
     * @param int $courseid
     * @param \Throwable $e
     * @param \stdClass|null $userenrolment user_enrolments record, if it could be loaded.
     * @return void
     */
    protected static function report_failure(int $courseid, \Throwable $e, ?\stdClass $userenrolment = null): void {
        $message = "tool_ltigroupautoenrol: assignment of a new LTI enrolment in course {$courseid} failed: " . get_class($e);
        try {
            static::record_failure($courseid);
        } catch (\Throwable $persist) {
            $message .= ' (the failure could not be recorded: ' . get_class($persist) . ')';
        }
        // Always logged, independent of the debugging level, so production sites see it too.
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log($message);
        debugging($message, DEBUG_NORMAL);
        if ($userenrolment) {
            event_reporter::failure(
                $courseid,
                (int) $userenrolment->enrolid,
                0,
                (int) $userenrolment->userid,
                group_assignment_base::SOURCE_EVENT,
                event_reporter::code_of($e),
                1
            );
        }
    }

    /**
     * Counts one unexpected failure of the event path for the course.
     *
     * @param int $courseid
     * @return void
     */
    protected static function record_failure(int $courseid): void {
        config_repository::record_event_errors($courseid, 1);
    }

    /**
     * Makes failed assignments of the event path visible (course and count only, no user data).
     *
     * The enrolment itself is never affected. The failures are counted per course and shown as a
     * warning on the settings page until a backfill has verified that no eligible participant misses
     * a membership any more, and they are written to the web server log; developers also get a
     * debugging message.
     *
     * @param int $courseid
     * @param assignment_result|null $result
     * @return void
     */
    public static function report(int $courseid, ?assignment_result $result): void {
        if ($result && $result->errors > 0) {
            config_repository::record_event_errors($courseid, $result->errors);
            // Always logged, independent of the debugging level, so production sites see it too.
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
            error_log("tool_ltigroupautoenrol: {$result->errors} group membership(s) could not be added in course {$courseid}");
            debugging(
                "tool_ltigroupautoenrol: {$result->errors} group membership(s) could not be added in course {$courseid}",
                DEBUG_NORMAL
            );
        }
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
