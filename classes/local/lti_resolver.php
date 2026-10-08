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

namespace tool_ltigroupautoenrol\local;

/**
 * Resolves LTI 1.3 tools (enrol_lti "published resources") and their eligible enrolments.
 *
 * Eligibility contract (shared by the enrolment observer and the backfill):
 * a user enrolment counts only if it belongs to the enrol_lti instance of the tool,
 * the enrol instance is enabled, the user enrolment is active (not suspended),
 * the current time lies within timestart/timeend and the user is not deleted.
 * Suspended, expired and not-yet-started enrolments are excluded.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lti_resolver {
    /** @var string The only supported LTI version. */
    public const LTI_VERSION = 'LTI-1p3';

    /**
     * Returns the LTI 1.3 tools published from a course (including tools of disabled instances).
     *
     * @param int $courseid
     * @return array<int, \stdClass> enrol_lti_tools records joined with enrol fields, keyed by tool id.
     */
    public static function get_course_tools(int $courseid): array {
        return \enrol_lti\helper::get_lti_tools(['courseid' => $courseid, 'ltiversion' => self::LTI_VERSION]);
    }

    /**
     * Returns the LTI 1.3 tool that belongs to an enrol instance of a course.
     *
     * @param int $courseid
     * @param int $enrolid enrol.id of an enrol_lti instance.
     * @return \stdClass|null
     */
    public static function get_tool_for_enrol_instance(int $courseid, int $enrolid): ?\stdClass {
        $tools = \enrol_lti\helper::get_lti_tools([
            'courseid' => $courseid,
            'enrolid' => $enrolid,
            'ltiversion' => self::LTI_VERSION,
        ]);
        return $tools ? reset($tools) : null;
    }

    /**
     * Returns a human readable name of a tool.
     *
     * @param \stdClass $tool
     * @return string Already formatted, safe for output.
     */
    public static function get_tool_name(\stdClass $tool): string {
        try {
            $name = \enrol_lti\helper::get_name($tool);
        } catch (\Throwable $e) {
            $name = '';
        }
        if ($name === null || $name === '') {
            return get_string('tool_fallbackname', 'tool_ltigroupautoenrol', (int) $tool->id);
        }
        return format_string($name, true, ['context' => \context_course::instance($tool->courseid)]);
    }

    /**
     * Whether a user enrolment is eligible for automatic group assignment.
     *
     * @param \stdClass $userenrolment user_enrolments record.
     * @param \stdClass $tool Tool record as returned by {@see get_course_tools()} (contains enrol status).
     * @param int|null $now Timestamp, defaults to time().
     * @return bool
     */
    public static function is_enrolment_eligible(\stdClass $userenrolment, \stdClass $tool, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        if ((int) $userenrolment->enrolid !== (int) $tool->enrolid) {
            return false;
        }
        if ((int) $tool->status !== ENROL_INSTANCE_ENABLED || (int) $userenrolment->status !== ENROL_USER_ACTIVE) {
            return false;
        }
        if ((int) $userenrolment->timestart > $now) {
            return false;
        }
        if (!empty($userenrolment->timeend) && (int) $userenrolment->timeend <= $now) {
            return false;
        }
        return $DB->record_exists('user', ['id' => $userenrolment->userid, 'deleted' => 0]);
    }

    /**
     * Returns the SQL fragment selecting eligible user ids of a tool.
     *
     * @param \stdClass $tool
     * @param int $now
     * @param int|null $createdbefore Approved preview time: only enrolments that existed, were unchanged and had started then.
     * @return array{0: string, 1: array} SQL (select of column "userid") and parameters.
     */
    public static function get_eligible_users_sql(\stdClass $tool, int $now, ?int $createdbefore = null): array {
        $sql = "SELECT ue.userid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE ue.enrolid = :ltienrolid
                   AND e.enrol = :ltienrol
                   AND e.courseid = :lticourseid
                   AND e.status = :ltienrolenabled
                   AND ue.status = :ltiueactive
                   AND ue.timestart <= :ltinow1
                   AND (ue.timeend = 0 OR ue.timeend > :ltinow2)
                   AND u.deleted = 0";
        if ($createdbefore !== null) {
            // Approved set: the enrolment existed and was unchanged and already started at preview time,
            // so it was eligible then and is still eligible now. Suspensions lifted or start dates reached
            // after the preview need a new confirmation (or are handled as new enrolments by the observer).
            $sql .= " AND ue.timecreated <= :lticreatedbefore
                      AND ue.timemodified <= :ltiunchangedsince
                      AND ue.timestart <= :ltistartedbefore";
        }
        $params = [
            'ltienrolid' => $tool->enrolid,
            'ltienrol' => 'lti',
            'lticourseid' => $tool->courseid,
            'ltienrolenabled' => ENROL_INSTANCE_ENABLED,
            'ltiueactive' => ENROL_USER_ACTIVE,
            'ltinow1' => $now,
            'ltinow2' => $now,
        ];
        if ($createdbefore !== null) {
            $params['lticreatedbefore'] = $createdbefore;
            $params['ltiunchangedsince'] = $createdbefore;
            $params['ltistartedbefore'] = $createdbefore;
        }
        return [$sql, $params];
    }
}
