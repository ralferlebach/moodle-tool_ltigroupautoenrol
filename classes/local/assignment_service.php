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
 * Assigns LTI-enrolled users to the groups mapped to their LTI tool.
 *
 * Shared by the enrolment observer (one user) and the backfill (all eligible users).
 * Assignment is strictly additive: memberships are only added through the Moodle group API,
 * never removed. Repeating a run is idempotent.
 *
 * Error policy: the synchronous event path must never break an enrolment, so failures are
 * counted in the result instead of being thrown; the backfill reports the counters to its caller.
 *
 * @package    tool_ltigroupautoenrol
 * @copyright  2026 Ralf Erlebach
 * @author     Ralf Erlebach - https://github.com/ralferlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assignment_service {
    /** @var int Default number of users processed per database batch in the backfill. */
    public const BATCH_SIZE = 500;

    /**
     * Assigns one user to the groups mapped to a tool.
     *
     * The caller is responsible for checking the eligibility of the enrolment.
     *
     * @param course_config $config Configuration of the tool's course.
     * @param \stdClass $tool Tool record (must belong to $config->courseid).
     * @param int $userid
     * @param array|null $coursegroups Groups of the course keyed by id; loaded if null.
     * @return assignment_result
     */
    public static function assign_user(
        course_config $config,
        \stdClass $tool,
        int $userid,
        ?array $coursegroups = null
    ): assignment_result {
        global $CFG;
        require_once($CFG->dirroot . '/group/lib.php');

        $result = new assignment_result();
        $groupids = $config->mapping->get_groupids((int) $tool->id);
        if (!$config->enabled || !$groupids) {
            $result->skipped++;
            return $result;
        }
        if ((int) $tool->courseid !== $config->courseid) {
            // Invariant: a tool of another course must never drive assignments.
            $result->errors++;
            return $result;
        }
        $coursegroups = $coursegroups ?? groups_get_all_groups($config->courseid);
        $result->eligible = 1;
        foreach ($groupids as $groupid) {
            if (!isset($coursegroups[$groupid])) {
                // Group was deleted or belongs to another course.
                $result->skipped++;
                continue;
            }
            if (groups_is_member($groupid, $userid)) {
                $result->alreadymember++;
                continue;
            }
            try {
                if (groups_add_member($coursegroups[$groupid], $userid)) {
                    $result->added++;
                } else {
                    $result->errors++;
                }
            } catch (\moodle_exception $e) {
                $result->errors++;
            }
        }
        return $result;
    }

    /**
     * Handles a newly created user enrolment (called by the event observer).
     *
     * @param int $courseid
     * @param \stdClass $userenrolment user_enrolments record.
     * @return assignment_result|null Null if the plugin is not active for the course or the enrolment is not an LTI 1.3 one.
     */
    public static function handle_new_enrolment(int $courseid, \stdClass $userenrolment): ?assignment_result {
        $config = config_repository::get($courseid);
        if (!$config || !$config->enabled || $config->mapping->is_empty()) {
            // Feature off: no LTI lookup at all.
            return null;
        }
        $tool = lti_resolver::get_tool_for_enrol_instance($courseid, (int) $userenrolment->enrolid);
        if (!$tool) {
            return null;
        }
        if (!lti_resolver::is_enrolment_eligible($userenrolment, $tool)) {
            $result = new assignment_result();
            $result->skipped = count($config->mapping->get_groupids((int) $tool->id));
            return $result;
        }
        return self::assign_user($config, $tool, (int) $userenrolment->userid);
    }

    /**
     * Computes the backfill preview of a course without changing anything.
     *
     * @param course_config $config
     * @param int|null $now
     * @return array<int, \stdClass> Per mapped tool: tool, name, groups, eligible (users), missing (memberships).
     */
    public static function preview(course_config $config, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $tools = lti_resolver::get_course_tools($config->courseid);
        $coursegroups = groups_get_all_groups($config->courseid);
        $rows = [];
        foreach ($config->mapping->get_toolids() as $toolid) {
            if (!isset($tools[$toolid])) {
                continue;
            }
            $tool = $tools[$toolid];
            [$eligiblesql, $params] = lti_resolver::get_eligible_users_sql($tool, $now);
            $groups = [];
            $missing = 0;
            foreach ($config->mapping->get_groupids($toolid) as $groupid) {
                if (!isset($coursegroups[$groupid])) {
                    continue;
                }
                $groups[$groupid] = $coursegroups[$groupid];
                $missing += $DB->count_records_sql(
                    "SELECT COUNT(1)
                       FROM ($eligiblesql) eligible
                      WHERE NOT EXISTS (
                            SELECT 1 FROM {groups_members} gm
                             WHERE gm.groupid = :groupid AND gm.userid = eligible.userid)",
                    $params + ['groupid' => $groupid]
                );
            }
            $rows[$toolid] = (object) [
                'tool' => $tool,
                'name' => lti_resolver::get_tool_name($tool),
                'groups' => $groups,
                'eligible' => $DB->count_records_sql("SELECT COUNT(1) FROM ($eligiblesql) eligible", $params),
                'missing' => $missing,
            ];
        }
        return $rows;
    }

    /**
     * Assigns all currently eligible users of all mapped tools of a course (backfill).
     *
     * @param course_config $config
     * @param int $batchsize Users per database batch.
     * @param int|null $now
     * @return assignment_result
     */
    public static function backfill(course_config $config, int $batchsize = self::BATCH_SIZE, ?int $now = null): assignment_result {
        global $DB;
        $now = $now ?? time();
        $total = new assignment_result();
        if (!$config->enabled) {
            return $total;
        }
        $tools = lti_resolver::get_course_tools($config->courseid);
        $coursegroups = groups_get_all_groups($config->courseid);
        foreach ($config->mapping->get_toolids() as $toolid) {
            if (!isset($tools[$toolid])) {
                continue;
            }
            [$eligiblesql, $params] = lti_resolver::get_eligible_users_sql($tools[$toolid], $now);
            $lastuserid = 0;
            do {
                // Keyset pagination keeps batches stable even if memberships change meanwhile.
                $userids = array_keys($DB->get_records_sql(
                    "SELECT DISTINCT eligible.userid FROM ($eligiblesql) eligible
                      WHERE eligible.userid > :lastuserid
                   ORDER BY eligible.userid",
                    $params + ['lastuserid' => $lastuserid],
                    0,
                    $batchsize
                ));
                foreach ($userids as $userid) {
                    $total->merge(self::assign_user($config, $tools[$toolid], (int) $userid, $coursegroups));
                    $lastuserid = (int) $userid;
                }
            } while (count($userids) === $batchsize);
        }
        return $total;
    }
}
