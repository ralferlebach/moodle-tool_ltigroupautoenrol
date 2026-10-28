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

use tool_ltigroupautoenrol\event\group_assignment_base;
use tool_ltigroupautoenrol\event\group_assignment_failed;

/**
 * Assigns LTI-enrolled users to the groups mapped to their LTI tool.
 *
 * Shared by the enrolment observer (one user) and the backfill (all eligible users).
 * Assignment is strictly additive: memberships are only added through the Moodle group API,
 * never removed. Repeating a run is idempotent.
 *
 * Error policy: the synchronous event path must never break an enrolment, so failures are
 * counted in the result instead of being thrown; the backfill reports the counters to its caller.
 * Every outcome is also reported as event (ids and counters only, see {@see event_reporter}).
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
     * @var int Upper bound of (user, group) membership rows prefetched per backfill batch; the batch
     * is reduced for tools mapped to many groups, so the memory of a batch stays bounded.
     */
    public const MAX_PREFETCH_ROWS = 50000;

    /**
     * Assigns one user to the groups mapped to a tool.
     *
     * The caller is responsible for checking the eligibility of the enrolment. No event is triggered here.
     *
     * @param course_config $config Configuration of the tool's course.
     * @param \stdClass $tool Tool record (must belong to $config->courseid).
     * @param int $userid
     * @param array|null $coursegroups Groups of the course keyed by id; loaded if null.
     * @param array|null $memberof Group ids (as keys) of the mapped groups the user is known to be a member of,
     *                             prefetched by the backfill; null looks up every membership.
     * @return assignment_result
     */
    public static function assign_user(
        course_config $config,
        \stdClass $tool,
        int $userid,
        ?array $coursegroups = null,
        ?array $memberof = null
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
            $result->errorcode = group_assignment_failed::CODE_TOOL_COURSE_MISMATCH;
            return $result;
        }
        $coursegroups = $coursegroups ?? groups_get_all_groups($config->courseid);
        $result->eligible = 1;
        foreach ($groupids as $groupid) {
            if (!isset($coursegroups[$groupid])) {
                // Group was deleted or belongs to another course.
                $result->skipped++;
            } else if ($memberof !== null ? isset($memberof[$groupid]) : groups_is_member($groupid, $userid)) {
                $result->alreadymember++;
            } else {
                self::add_member($coursegroups[$groupid], $userid, $result);
            }
        }
        return $result;
    }

    /**
     * Adds one membership through the Moodle group API and counts the outcome.
     *
     * @param \stdClass $group
     * @param int $userid
     * @param assignment_result $result
     * @return void
     */
    private static function add_member(\stdClass $group, int $userid, assignment_result $result): void {
        $code = '';
        try {
            if (groups_add_member($group, $userid)) {
                $result->added++;
                return;
            }
            $code = group_assignment_failed::CODE_ADD_MEMBER_FAILED;
        } catch (\moodle_exception $e) {
            $code = event_reporter::code_of($e);
        }
        $result->errors++;
        if ($result->errorcode === '') {
            $result->errorcode = $code;
        }
    }

    /**
     * Handles a newly created user enrolment (called by the event observer).
     *
     * Triggers group_assignment_completed for an eligible enrolment of a mapped tool, and
     * group_assignment_failed if memberships could not be added.
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
        $result = self::assign_user($config, $tool, (int) $userenrolment->userid);
        event_reporter::user_result(
            $courseid,
            $tool,
            (int) $userenrolment->userid,
            group_assignment_base::SOURCE_EVENT,
            $result,
            false
        );
        return $result;
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
            $groups = array_intersect_key($coursegroups, array_flip($config->mapping->get_groupids($toolid)));
            $rows[$toolid] = (object) [
                'tool' => $tool,
                'name' => lti_resolver::get_tool_name($tool),
                'groups' => $groups,
                'eligible' => $DB->count_records_sql("SELECT COUNT(1) FROM ($eligiblesql) eligible", $params),
                'missing' => self::count_missing_memberships($config->courseid, array_keys($groups), $eligiblesql, $params),
            ];
        }
        return $rows;
    }

    /**
     * Counts the memberships the plugin would currently add in a course (all mapped tools, all eligible users).
     *
     * Zero means that every currently eligible LTI participant is a member of all groups mapped to their tool.
     *
     * @param course_config $config
     * @param int|null $now
     * @return int
     */
    public static function count_missing(course_config $config, ?int $now = null): int {
        return array_sum(array_column(self::preview($config, $now), 'missing'));
    }

    /**
     * Counts missing memberships of eligible users in a set of groups with one query, whatever the number of groups.
     *
     * @param int $courseid
     * @param int[] $groupids Existing groups of the course.
     * @param string $eligiblesql Subquery returning the column "userid".
     * @param array $params Parameters of the subquery.
     * @return int
     */
    private static function count_missing_memberships(int $courseid, array $groupids, string $eligiblesql, array $params): int {
        global $DB;
        if (!$groupids) {
            return 0;
        }
        [$sql, $params] = self::get_missing_memberships_sql($courseid, $groupids, $eligiblesql, $params);
        return $DB->count_records_sql($sql, $params);
    }

    /**
     * Returns the query counting missing memberships (also used by the benchmark in tests/load to show its plan).
     *
     * @param int $courseid
     * @param int[] $groupids Existing groups of the course (not empty).
     * @param string $eligiblesql Subquery returning the column "userid".
     * @param array $params Parameters of the subquery.
     * @return array{0: string, 1: array} SQL and parameters.
     */
    public static function get_missing_memberships_sql(int $courseid, array $groupids, string $eligiblesql, array $params): array {
        global $DB;
        [$insql, $inparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'ltigrp');
        $sql = "SELECT COUNT(1)
                  FROM ($eligiblesql) eligible
                  JOIN {groups} g ON g.courseid = :ltigrpcourseid AND g.id $insql
                 WHERE NOT EXISTS (
                       SELECT 1 FROM {groups_members} gm
                        WHERE gm.groupid = g.id AND gm.userid = eligible.userid)";
        return [$sql, $params + $inparams + ['ltigrpcourseid' => $courseid]];
    }

    /**
     * Assigns all currently eligible users of all mapped tools of a course (backfill).
     *
     * With $createdbefore, only enrolments that already existed at that time are considered: the
     * set the user approved in the preview. Later enrolments are handled by the enrolment observer.
     *
     * @param course_config $config
     * @param int $batchsize Users per database batch.
     * @param int|null $now
     * @param int|null $createdbefore Time of the approved preview.
     * @return assignment_result
     */
    public static function backfill(
        course_config $config,
        int $batchsize = self::BATCH_SIZE,
        ?int $now = null,
        ?int $createdbefore = null
    ): assignment_result {
        $now = $now ?? time();
        $total = new assignment_result();
        if (!$config->enabled) {
            return $total;
        }
        $tools = lti_resolver::get_course_tools($config->courseid);
        $coursegroups = groups_get_all_groups($config->courseid);
        foreach ($config->mapping->get_toolids() as $toolid) {
            if (isset($tools[$toolid])) {
                $total->merge(self::backfill_tool($config, $tools[$toolid], $coursegroups, $batchsize, $now, $createdbefore));
            }
        }
        return $total;
    }

    /**
     * Backfill of one tool: keyset batches of eligible users, memberships of a batch prefetched with one query.
     *
     * A user gets a group_assignment_completed event only if a membership was added or failed (a repeated run
     * over assigned users logs nothing). An exception aborts the run, is reported as group_assignment_failed
     * of the tool and is passed on, so the task API retries the (idempotent) run.
     *
     * @param course_config $config
     * @param \stdClass $tool
     * @param array $coursegroups Groups of the course keyed by id.
     * @param int $batchsize
     * @param int $now
     * @param int|null $createdbefore
     * @return assignment_result
     */
    private static function backfill_tool(
        course_config $config,
        \stdClass $tool,
        array $coursegroups,
        int $batchsize,
        int $now,
        ?int $createdbefore
    ): assignment_result {
        $result = new assignment_result();
        $groupids = array_values(array_intersect($config->mapping->get_groupids((int) $tool->id), array_keys($coursegroups)));
        $batchsize = self::effective_batch_size($batchsize, count($groupids));
        [$eligiblesql, $params] = lti_resolver::get_eligible_users_sql($tool, $now, $createdbefore);
        $lastuserid = 0;
        try {
            do {
                // Keyset pagination keeps batches stable even if memberships change meanwhile.
                $userids = self::next_batch($eligiblesql, $params, $lastuserid, $batchsize);
                $fetched = count($userids);
                $memberships = self::get_memberships($userids, $groupids);
                foreach ($userids as $userid) {
                    $userresult = self::assign_user($config, $tool, $userid, $coursegroups, $memberships[$userid] ?? []);
                    event_reporter::user_result(
                        $config->courseid,
                        $tool,
                        $userid,
                        group_assignment_base::SOURCE_BACKFILL,
                        $userresult,
                        true
                    );
                    $result->merge($userresult);
                    $lastuserid = $userid;
                }
            } while ($fetched === $batchsize);
        } catch (\Throwable $e) {
            event_reporter::failure(
                $config->courseid,
                (int) $tool->enrolid,
                (int) $tool->id,
                null,
                group_assignment_base::SOURCE_BACKFILL,
                event_reporter::code_of($e),
                1
            );
            throw $e;
        }
        return $result;
    }

    /**
     * Number of users per batch so that the prefetched memberships stay below {@see MAX_PREFETCH_ROWS}.
     *
     * @param int $batchsize Requested batch size.
     * @param int $groupcount Number of existing groups mapped to the tool.
     * @return int At least 1.
     */
    public static function effective_batch_size(int $batchsize, int $groupcount): int {
        return max(1, min($batchsize, intdiv(self::MAX_PREFETCH_ROWS, max(1, $groupcount))));
    }

    /**
     * Returns the next batch of eligible user ids after $lastuserid, in ascending order.
     *
     * @param string $eligiblesql
     * @param array $params
     * @param int $lastuserid
     * @param int $batchsize
     * @return int[]
     */
    private static function next_batch(string $eligiblesql, array $params, int $lastuserid, int $batchsize): array {
        global $DB;
        $records = $DB->get_records_sql(
            "SELECT DISTINCT eligible.userid FROM ($eligiblesql) eligible
              WHERE eligible.userid > :lastuserid
           ORDER BY eligible.userid",
            $params + ['lastuserid' => $lastuserid],
            0,
            $batchsize
        );
        return array_map('intval', array_keys($records));
    }

    /**
     * Prefetches the memberships of a batch of users in the mapped groups with one query.
     *
     * @param int[] $userids
     * @param int[] $groupids Existing mapped groups.
     * @return array<int, array<int, true>> User id => group ids (as keys) the user is a member of.
     */
    private static function get_memberships(array $userids, array $groupids): array {
        global $DB;
        if (!$userids || !$groupids) {
            return [];
        }
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ltiusr');
        [$groupsql, $groupparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'ltigrp');
        $rs = $DB->get_recordset_sql(
            "SELECT gm.id, gm.userid, gm.groupid
               FROM {groups_members} gm
              WHERE gm.userid $usersql AND gm.groupid $groupsql",
            $userparams + $groupparams
        );
        $memberships = [];
        foreach ($rs as $row) {
            $memberships[(int) $row->userid][(int) $row->groupid] = true;
        }
        $rs->close();
        return $memberships;
    }
}
