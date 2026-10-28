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

use tool_ltigroupautoenrol\event\group_assignment_completed;
use tool_ltigroupautoenrol\event\group_assignment_failed;

/**
 * Triggers the group assignment events (ids, counters and machine codes only).
 *
 * Reporting must never change the outcome of an assignment: a failure to trigger an event is
 * swallowed and reported as developer debugging (class name only). Other plugins, such as
 * tool_ltidiagnostics, may read the events from the log; this plugin does not depend on them.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class event_reporter {
    /**
     * Reports the outcome of the assignment of one user to the groups of a tool.
     *
     * Triggers group_assignment_completed if the user was eligible (and, with $onlychanges, only if a
     * membership was added or failed) and group_assignment_failed if memberships could not be added.
     *
     * @param int $courseid
     * @param \stdClass $tool Tool record (id, enrolid).
     * @param int $userid
     * @param string $source group_assignment_base::SOURCE_EVENT or SOURCE_BACKFILL.
     * @param assignment_result $result Result of the assignment of this user only.
     * @param bool $onlychanges Skip the completed event if nothing was added and nothing failed (backfill).
     * @return void
     */
    public static function user_result(
        int $courseid,
        \stdClass $tool,
        int $userid,
        string $source,
        assignment_result $result,
        bool $onlychanges
    ): void {
        $changed = $result->added > 0 || $result->errors > 0;
        if ($result->eligible > 0 && ($changed || !$onlychanges)) {
            self::trigger(group_assignment_completed::class, $courseid, (int) $tool->enrolid, $userid, [
                'source' => $source,
                'added' => $result->added,
                'existing' => $result->alreadymember,
                'errors' => $result->errors,
                'toolid' => (int) $tool->id,
            ]);
        }
        if ($result->errors > 0) {
            $code = $result->errorcode !== '' ? $result->errorcode : group_assignment_failed::CODE_ADD_MEMBER_FAILED;
            self::failure($courseid, (int) $tool->enrolid, (int) $tool->id, $userid, $source, $code, $result->errors);
        }
    }

    /**
     * Reports a failure (group_assignment_failed).
     *
     * @param int $courseid
     * @param int $enrolid Enrol instance of the tool.
     * @param int $toolid 0 if unknown.
     * @param int|null $userid Null for a failure of a whole backfill run.
     * @param string $source
     * @param string $code Machine code or exception class basename.
     * @param int $errors
     * @return void
     */
    public static function failure(
        int $courseid,
        int $enrolid,
        int $toolid,
        ?int $userid,
        string $source,
        string $code,
        int $errors
    ): void {
        self::trigger(group_assignment_failed::class, $courseid, $enrolid, $userid, [
            'source' => $source,
            'code' => $code,
            'errors' => max(0, $errors),
            'toolid' => max(0, $toolid),
        ]);
    }

    /**
     * Returns the machine code of an exception: the basename of its class (no message, no user data).
     *
     * @param \Throwable $e
     * @return string
     */
    public static function code_of(\Throwable $e): string {
        return self::basename(get_class($e));
    }

    /**
     * Returns the class name without namespace.
     *
     * @param string $class
     * @return string
     */
    private static function basename(string $class): string {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * Creates and triggers an event; never throws.
     *
     * @param string $class Event class.
     * @param int $courseid
     * @param int $enrolid
     * @param int|null $userid
     * @param array $other
     * @return void
     */
    private static function trigger(string $class, int $courseid, int $enrolid, ?int $userid, array $other): void {
        try {
            $data = [
                'context' => \context_course::instance($courseid),
                'courseid' => $courseid,
                'objectid' => $enrolid,
                'other' => $other,
            ];
            if ($userid) {
                $data['relateduserid'] = $userid;
            }
            $class::create($data)->trigger();
        } catch (\Throwable $e) {
            debugging('tool_ltigroupautoenrol: event ' . self::basename($class) . ' could not be triggered: ' .
                self::code_of($e), DEBUG_DEVELOPER);
        }
    }
}
